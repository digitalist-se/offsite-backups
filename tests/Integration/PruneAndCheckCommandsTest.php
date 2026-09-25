<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneAndCheckCommandsTest extends IntegrationTestCase
{
    private function seed(): void
    {
        $restic = $this->restic();
        $db = $this->repositoryUrl('database');
        $files = $this->repositoryUrl('public-files');
        $restic->init($db);
        $restic->init($files);
        $put = function (string $tag, string $day, string $host = 'proj-main') use ($restic, $db): void {
            $restic->backupFromShell($db, 'printf "%s" "$C"', ['C' => "dump $day"], "$day-proj-main.sql", $tag, $host, "$day 01:00:00");
        };
        foreach (range(2, 10) as $d) {
            $put('daily', sprintf('2026-09-%02d', $d));
        }
        $put('biweekly', '2026-08-15');
        $put('biweekly', '2026-09-15');
        $put('monthly', '2026-08-01');
        $put('monthly', '2026-09-01');
        $put('daily', '2026-09-11', 'other-host');
        $dir = $this->projectRoot . '/files';
        mkdir($dir);
        file_put_contents("$dir/a.txt", 'a');
        foreach (['2026-09-08', '2026-09-09', '2026-09-10'] as $day) {
            $restic->backupPaths($files, [$dir], [], 'daily', 'proj-main', "$day 02:00:00");
        }
    }

    public function testDryRunReportsWithoutRemovingThenRealRunPrunesAndCheckPasses(): void
    {
        $this->seed();
        $app = new Application($this->projectRoot, $this->environment());
        $db = $this->repositoryUrl('database');
        $prune = new CommandTester($app->find('prune'));

        self::assertSame(0, $prune->execute(['--dry-run' => true]), $prune->getDisplay());
        self::assertStringContainsString('Retention dry run finished: db would forget=3, files would forget=0', $prune->getDisplay());
        self::assertStringNotContainsString('Retention finished successfully', $prune->getDisplay());
        self::assertCount(14, $this->restic()->snapshots($db));

        self::assertSame(0, $prune->execute([]), $prune->getDisplay());
        self::assertStringContainsString('Retention finished successfully: db forgotten=3, files forgotten=0', $prune->getDisplay());
        $mine = $this->restic()->snapshots($db, ['host' => 'proj-main']);
        self::assertCount(10, $mine);
        $days = array_map(static fn ($s) => $s->time->format('Y-m-d'), $mine);
        self::assertNotContains('2026-09-02', $days);
        self::assertNotContains('2026-09-03', $days);
        self::assertNotContains('2026-08-15', $days);
        self::assertContains('2026-08-01', $days, 'monthly kept (12 allowed)');
        self::assertCount(1, $this->restic()->snapshots($db, ['host' => 'other-host']), 'foreign host untouched');

        self::assertSame(0, $prune->execute(['store' => 'files']));
        self::assertStringContainsString('Retention finished successfully: db forgotten=-, files forgotten=0', $prune->getDisplay());

        $check = new CommandTester($app->find('check'));
        self::assertSame(0, $check->execute([]), $check->getDisplay());
        self::assertStringContainsString('Repository check completed successfully: db, files', $check->getDisplay());
        self::assertSame(0, $check->execute(['store' => 'db']));
        self::assertStringContainsString('Repository check completed successfully: db', $check->getDisplay());
        self::assertSame(1, $check->execute(['store' => 'nope']));
    }

    public function testMissingRepositoryFailsTheRunButProcessesTheOtherStore(): void
    {
        $this->restic()->init($this->repositoryUrl('public-files'));
        $app = new Application($this->projectRoot, $this->environment());
        $prune = new CommandTester($app->find('prune'));
        self::assertSame(1, $prune->execute([]));
        self::assertStringContainsString('Retention failed for db', $prune->getDisplay());
        self::assertStringContainsString('Pruned ' . $this->repositoryUrl('public-files'), $prune->getDisplay());
    }
}
