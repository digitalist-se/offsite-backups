<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;
use Digitalist\OffsiteBackup\Tests\Support\TestEnv;
use Symfony\Component\Console\Tester\CommandTester;

final class DbBackupCommandTest extends IntegrationTestCase
{
    /** @param array<string,string> $env */
    private function app(array $env = []): Application
    {
        return new Application($this->projectRoot, $this->environment([
            'OFFSITE_BACKUP_DB_DUMP_COMMAND' => __DIR__ . '/../Support/fake-dump.sh --result-file={file} --structure-tables-list={structure_tables}',
            'OFFSITE_BACKUP_DB_MIN_BYTES' => '100',
            'OFFSITE_BACKUP_LOG_DRUPAL' => '1',
            'OFFSITE_BACKUP_DRUSH_BIN' => __DIR__ . '/../Support/fake-drush.php',
        ] + $env));
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestEnv::set('FAKE_DRUSH_OUT', $this->projectRoot . '/drush.log');
        TestEnv::unset('FAKE_DRUSH_FAIL');
        TestEnv::unset('FAKE_DUMP_TRUNCATE');
        $this->restic()->init($this->repositoryUrl('database'));
    }

    protected function tearDown(): void
    {
        TestEnv::unset('FAKE_DRUSH_OUT');
        TestEnv::unset('FAKE_DUMP_TRUNCATE');
    }

    /** @return list<array<string,mixed>> */
    private function drushRecords(): array
    {
        return array_values(array_map(static fn (string $l): array => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($this->projectRoot . '/drush.log')))));
    }

    public function testBackupStoresVerifiesAndHandsOverToDrupal(): void
    {
        $tester = new CommandTester($this->app()->find('db:backup'));
        $code = $tester->execute([]);
        $display = $tester->getDisplay();
        self::assertSame(0, $code, $display);

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        self::assertMatchesRegularExpression("/Backup process finished successfully: $today-proj-main\\.sql \\(snapshot [0-9a-f]{8}\\)/", $display);
        $snapshots = $this->restic()->snapshots($this->repositoryUrl('database'), ['host' => 'proj-main']);
        self::assertCount(1, $snapshots);
        self::assertSame(["/$today-proj-main.sql"], $snapshots[0]->paths);
        self::assertContains($snapshots[0]->tags[0], ['daily', 'biweekly', 'monthly']);
        self::assertFileDoesNotExist($this->projectRoot . "/backups/$today-proj-main.sql.gz", 'local dump removed');
        self::assertFileExists($this->projectRoot . '/backups/db-backup.lock');

        $records = $this->drushRecords();
        $last = end($records);
        $state = $last['stdin']['state']['offsite_backup.run.db_backup'];
        self::assertSame('success', $state['outcome']);
        self::assertSame("$today-proj-main.sql", $state['details']['name']);
        self::assertSame($snapshots[0]->shortId, $state['details']['snapshot']);
        self::assertSame(40, $state['details']['tables']);
        self::assertSame('Backup started. file=' . $this->projectRoot . "/backups/$today-proj-main.sql.gz tag={$snapshots[0]->tags[0]} project=proj env=main", $records[0]['stdin']['log'][0]['message']);
    }

    public function testSkippedOutsideEnabledEnvironmentTypesUnlessForced(): void
    {
        $app = $this->app(['PLATFORM_ENVIRONMENT_TYPE' => 'development']);
        $tester = new CommandTester($app->find('db:backup'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('skipped (environment type "development")', $tester->getDisplay());
        self::assertSame([], $this->restic()->snapshots($this->repositoryUrl('database')));
        self::assertFileDoesNotExist($this->projectRoot . '/drush.log', 'skipped runs are not handed to Drupal');

        self::assertSame(0, $tester->execute(['--force' => true]));
        self::assertCount(1, $this->restic()->snapshots($this->repositoryUrl('database')));
    }

    public function testTruncatedDumpFailsBeforeUploadAndNotifies(): void
    {
        TestEnv::set('FAKE_DUMP_TRUNCATE', '1');
        $tester = new CommandTester($this->app()->find('db:backup'));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString("does not end with the '-- Dump completed on' trailer", $tester->getDisplay());
        self::assertSame([], $this->restic()->snapshots($this->repositoryUrl('database')), 'nothing uploaded');
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        self::assertFileDoesNotExist($this->projectRoot . "/backups/$today-proj-main.sql.gz", 'local dump removed on failure too');
        $records = $this->drushRecords();
        $levels = array_merge(...array_map(static fn (array $r): array => array_column($r['stdin']['log'], 'level'), $records));
        self::assertContains('error', $levels);
        self::assertSame('failure', end($records)['stdin']['state']['offsite_backup.run.db_backup']['outcome']);
    }

    public function testMissingRepositoryFailsFastWithAHintAndDumpsNothing(): void
    {
        $tester = new CommandTester($this->app(['OFFSITE_BACKUP_DB_REPO' => 'not-initialised'])->find('db:backup'));
        $started = microtime(true);
        self::assertSame(1, $tester->execute([]));
        self::assertLessThan(60, microtime(true) - $started);
        self::assertStringContainsString('run `offsite-backup init`', $tester->getDisplay());
        self::assertStringNotContainsString('DB dump started', $tester->getDisplay());
    }

    public function testLeftoverDumpsOlderThanADayAreRemovedFirst(): void
    {
        if (!is_dir($this->projectRoot . '/backups')) {
            mkdir($this->projectRoot . '/backups', 0700, true);
        }
        $old = $this->projectRoot . '/backups/2020-01-01-proj-main.sql.gz';
        touch($old, time() - 2 * 86400);
        $tester = new CommandTester($this->app()->find('db:backup'));
        self::assertSame(0, $tester->execute([]));
        self::assertFileDoesNotExist($old);
        self::assertStringContainsString('Removed stale local file', $tester->getDisplay());
    }
    public function testTheFirstRunOfAMonthIsPromotedToMonthly(): void
    {
        $db = $this->repositoryUrl('database');
        $tester = new CommandTester($this->app()->find('db:backup'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $first = $this->restic()->snapshots($db, ['host' => 'proj-main']);
        self::assertSame(['monthly'], $first[0]->tags, 'an empty repository has no monthly for this month');
        $day = (int) (new \DateTimeImmutable())->format('d');
        if ($day !== 1) {
            self::assertStringContainsString('Class promoted to monthly: no monthly snapshot for', $tester->getDisplay());
        }

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $second = $this->restic()->snapshots($db, ['host' => 'proj-main'])[0];
        // Day 1: monthly again by date. From the 15th on: the month has no biweekly yet, so that is caught up. Otherwise daily.
        $expected = $day === 1 ? 'monthly' : ($day >= 15 ? 'biweekly' : 'daily');
        self::assertSame([$expected], $second->tags);
    }

    public function testSnapshotsRemovedOutsideTheToolFailEveryRunUntilAPruneResetsTheBaseline(): void
    {
        $db = $this->repositoryUrl('database');
        $tester = new CommandTester($this->app()->find('db:backup'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Snapshot inventory: ', $tester->getDisplay());
        self::assertFileExists($this->projectRoot . '/backups/inventory-db.json');
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $two = $this->restic()->snapshots($db, ['host' => 'proj-main']);
        self::assertCount(2, $two);

        // Someone forgets the newest of the two behind the tool's back: not the oldest, so only one class count changes.
        $forget = $this->restic()->tryRun($db, ['forget', $two[0]->id], null, 120);
        self::assertTrue($forget->ok(), $forget->stderr);

        self::assertSame(1, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Snapshots vanished', $tester->getDisplay());
        self::assertMatchesRegularExpression('/(daily|biweekly|monthly): (2 before, 1 now|1 before, 0 now)/', $tester->getDisplay());
        self::assertStringNotContainsString('Backup process finished successfully', $tester->getDisplay());
        self::assertCount(2, $this->restic()->snapshots($db, ['host' => 'proj-main']), 'the new snapshot was still made');
        $records = $this->drushRecords();
        self::assertSame('failure', end($records)['stdin']['state']['offsite_backup.run.db_backup']['outcome']);

        // The repository is back at two, but the loss stays on record: the next night fails as well.
        self::assertSame(1, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Snapshot loss not acknowledged', $tester->getDisplay());
        self::assertStringNotContainsString('Backup process finished successfully', $tester->getDisplay());
        self::assertCount(3, $this->restic()->snapshots($db, ['host' => 'proj-main']));
        $status = new CommandTester($this->app()->find('status'));
        self::assertSame(2, $status->execute([]));
        self::assertStringContainsString('snapshot loss not acknowledged', $status->getDisplay());

        // A real prune resets the baseline; the night after is fine again.
        $prune = new CommandTester($this->app()->find('prune'));
        self::assertSame(0, $prune->execute(['store' => 'db']), $prune->getDisplay());
        self::assertStringContainsString('Inventory baseline reset for db', $prune->getDisplay());
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        // status still exits 2 here because this test never initialises the files repository; the db lines are what matters.
        $status->execute([]);
        self::assertStringNotContainsString('vanished', $status->getDisplay());
        self::assertStringNotContainsString('not acknowledged', $status->getDisplay());
        self::assertMatchesRegularExpression('/^db inventory: .* \(baseline .* by db:backup\)$/m', $status->getDisplay());
    }
}
