<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Restic\Restic;
use Digitalist\OffsiteBackup\Restic\ResticException;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;

final class ResticTest extends IntegrationTestCase
{
    public function testInitBackupListForgetPruneCheckDumpRestore(): void
    {
        $restic = $this->restic();
        $repo = $this->repositoryUrl('database');

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $restic->version());
        self::assertFalse($restic->repositoryExists($repo));
        $restic->init($repo);
        self::assertTrue($restic->repositoryExists($repo));
        self::assertSame([], $restic->snapshots($repo));

        // Three stdin backups on different days, plus one from a foreign host.
        $content = str_repeat("CREATE TABLE t (id int);\n", 2000) . "-- Dump completed on 2026-09-25\n";
        $ids = [];
        foreach (['2026-09-20 01:00:00', '2026-09-21 01:00:00', '2026-09-22 01:00:00'] as $i => $time) {
            $summary = $restic->backupFromShell($repo, 'printf "%s" "$CONTENT"', ['CONTENT' => $content . $i], "2026-09-2$i-proj-main.sql", 'daily', 'proj-main', $time);
            self::assertSame(strlen($content) + 1, $summary->totalBytesProcessed);
            $ids[] = $summary->snapshotId;
        }
        $restic->backupFromShell($repo, 'printf "%s" "$CONTENT"', ['CONTENT' => 'other'], 'other.sql', 'daily', 'other-host', '2026-09-23 01:00:00');

        $mine = $restic->snapshots($repo, ['host' => 'proj-main', 'tag' => 'daily']);
        self::assertCount(3, $mine);
        self::assertSame('2026-09-22', $mine[0]->time->format('Y-m-d'), 'newest first');
        self::assertSame(['/2026-09-22-proj-main.sql'], $mine[0]->paths);
        self::assertSame(['daily'], $mine[0]->tags);
        self::assertSame(strlen($content) + 1, $mine[0]->totalBytesProcessed);
        self::assertCount(1, $restic->snapshots($repo, ['host' => 'proj-main', 'latest' => 1]));

        // Dry run reports one removal but keeps everything.
        self::assertSame(1, $restic->forget($repo, 'daily', 'proj-main', 2, true));
        self::assertCount(3, $restic->snapshots($repo, ['host' => 'proj-main']));
        self::assertSame(1, $restic->forget($repo, 'daily', 'proj-main', 2, false));
        $left = $restic->snapshots($repo, ['host' => 'proj-main']);
        self::assertCount(2, $left);
        self::assertNotContains($ids[0], array_map(static fn ($s) => $s->id, $left), 'oldest removed');
        self::assertCount(1, $restic->snapshots($repo, ['host' => 'other-host']), 'foreign host untouched');
        $restic->prune($repo);
        $restic->check($repo, '100%');

        // Dump round trip through gzip.
        $target = $this->projectRoot . '/dump.sql.gz';
        $bytes = $restic->dumpToGzip($repo, $left[0]->id, $left[0]->paths[0], $target);
        self::assertSame(strlen($content) + 1, $bytes);
        self::assertSame($content . '2', (string) file_get_contents('compress.zlib://' . $target));

        // Path backup with excludes and restore.
        $files = TempDir::create('offsite-backup-files-');
        mkdir("$files/css");
        file_put_contents("$files/keep.txt", 'keep');
        file_put_contents("$files/css/agg.css", 'drop');
        $filesRepo = $this->repositoryUrl('public-files');
        $restic->init($filesRepo);
        $summary = $restic->backupPaths($filesRepo, [$files], ['*/css'], 'daily', 'proj-main');
        $snap = $restic->snapshots($filesRepo, ['host' => 'proj-main'])[0];
        self::assertSame($summary->snapshotId, $snap->id);
        self::assertSame([$files], $snap->paths);
        $restoreDir = TempDir::create('offsite-backup-restore-');
        $restic->restore($filesRepo, $snap->id, $restoreDir, $files);
        self::assertSame('keep', file_get_contents($restoreDir . $files . '/keep.txt'));
        self::assertFileDoesNotExist($restoreDir . $files . '/css/agg.css');
    }

    public function testWrongPasswordIsAnErrorNotAMissingRepository(): void
    {
        $repo = $this->repositoryUrl('database');
        $this->restic()->init($repo);
        $s3 = $this->s3Env();
        $wrong = new Restic($this->runner(), $this->resticBin(), 'wrong', $s3['key'], $s3['secret'], $this->projectRoot . '/cache');
        $this->expectException(ResticException::class);
        $wrong->repositoryExists($repo);
    }
}
