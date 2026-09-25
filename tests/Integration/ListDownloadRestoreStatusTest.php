<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ListDownloadRestoreStatusTest extends IntegrationTestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application($this->projectRoot, $this->environment());
        $restic = $this->restic();
        $restic->init($this->repositoryUrl('database'));
        $restic->init($this->repositoryUrl('public-files'));
    }

    public function testListDownloadAndSelectorsIgnoreForeignHosts(): void
    {
        $restic = $this->restic();
        $db = $this->repositoryUrl('database');
        $old = $restic->backupFromShell($db, 'printf "%s" "$C"', ['C' => 'old dump'], '2026-09-20-proj-main.sql', 'daily', 'proj-main', '2026-09-20 01:00:00');
        $new = $restic->backupFromShell($db, 'printf "%s" "$C"', ['C' => 'new dump'], '2026-09-21-proj-main.sql', 'daily', 'proj-main', '2026-09-21 01:00:00');
        $restic->backupFromShell($db, 'printf "%s" "$C"', ['C' => 'foreign'], '2026-09-22-other.sql', 'daily', 'other-host', '2026-09-22 01:00:00');

        $list = new CommandTester($this->app->find('db:list'));
        self::assertSame(0, $list->execute([]));
        $display = $list->getDisplay();
        self::assertStringContainsString('2026-09-21-proj-main.sql', $display);
        self::assertStringNotContainsString('other', $display);
        self::assertLessThan(strpos($display, '2026-09-20-proj-main.sql'), strpos($display, '2026-09-21-proj-main.sql'), 'newest first');

        self::assertSame(0, $list->execute(['--json' => true]));
        $rows = json_decode($list->getDisplay(), true);
        self::assertSame([$new->snapshotId, $old->snapshotId], array_column($rows, 'id'));
        self::assertSame('daily', $rows[0]['class']);
        self::assertSame(8, $rows[0]['bytes']);

        $download = new CommandTester($this->app->find('db:download'));
        $to = $this->projectRoot . '/sql';
        self::assertSame(0, $download->execute(['snapshot' => 'latest', '--to' => $to]), $download->getDisplay());
        $lines = array_values(array_filter(explode("\n", trim($download->getDisplay()))));
        self::assertSame("$to/2026-09-21-proj-main.sql.gz", end($lines));
        self::assertSame('new dump', file_get_contents("compress.zlib://$to/2026-09-21-proj-main.sql.gz"));

        self::assertSame(0, $download->execute(['snapshot' => substr($old->snapshotId, 0, 8), '--to' => $to]));
        self::assertSame('old dump', file_get_contents("compress.zlib://$to/2026-09-20-proj-main.sql.gz"));

        self::assertSame(1, $download->execute(['snapshot' => 'ffffffff', '--to' => $to]));
        self::assertStringContainsString('No snapshot matching', $download->getDisplay());
    }

    public function testFilesRestoreCopiesTheSnapshotPathIntoTheTarget(): void
    {
        $src = $this->projectRoot . '/web/sites/default/files';
        mkdir("$src/sub", 0700, true);
        file_put_contents("$src/a.txt", 'A');
        file_put_contents("$src/sub/b.txt", 'B');
        $this->restic()->backupPaths($this->repositoryUrl('public-files'), [$src], [], 'daily', 'proj-main');

        $target = $this->projectRoot . '/restored-files';
        mkdir($target);
        file_put_contents("$target/local-only.txt", 'L');
        $restore = new CommandTester($this->app->find('files:restore'));
        self::assertSame(0, $restore->execute(['--target' => $target]), $restore->getDisplay());
        self::assertSame('A', file_get_contents("$target/a.txt"));
        self::assertSame('B', file_get_contents("$target/sub/b.txt"));
        self::assertSame('L', file_get_contents("$target/local-only.txt"), 'extra local files are kept');
        self::assertStringContainsString("Restored $src", $restore->getDisplay());
        self::assertSame([], glob($this->projectRoot . '/backups/restore-*') ?: [], 'temporary restore dir removed');
    }

    public function testStatusReportsAgesAndExitsTwoWhenStale(): void
    {
        $restic = $this->restic();
        $restic->backupFromShell($this->repositoryUrl('database'), 'printf "%s" "$C"', ['C' => 'x'], 'd.sql', 'daily', 'proj-main');
        $dir = $this->projectRoot . '/f';
        mkdir($dir);
        file_put_contents("$dir/a", 'a');
        $restic->backupPaths($this->repositoryUrl('public-files'), [$dir], [], 'daily', 'proj-main');
        $restic->backupPaths($this->repositoryUrl('public-files'), [$dir], [], 'monthly', 'proj-main', '2026-09-01 02:00:00');

        $status = new CommandTester($this->app->find('status'));
        self::assertSame(0, $status->execute([]), $status->getDisplay());
        self::assertStringContainsString('db', $status->getDisplay());
        self::assertStringContainsString('monthly', $status->getDisplay());

        self::assertSame(0, $status->execute(['--json' => true]));
        $json = json_decode($status->getDisplay(), true);
        self::assertFalse($json['stale']);
        self::assertSame(93600, $json['max_age_seconds']);

        sleep(2);
        self::assertSame(2, $status->execute(['--max-age' => '1s']));
        self::assertStringContainsString('STALE', $status->getDisplay());
    }

    public function testStatusIsStaleWithoutAnyDatabaseSnapshot(): void
    {
        $status = new CommandTester($this->app->find('status'));
        self::assertSame(2, $status->execute([]));
        self::assertStringContainsString('db: no snapshot', $status->getDisplay());
    }
}
