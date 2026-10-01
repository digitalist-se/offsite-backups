<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Restic\RepositoryStats;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;

final class ResticStatsTest extends IntegrationTestCase
{
    public function testStatsReportStoredSizeAndSnapshotCount(): void
    {
        $restic = $this->restic();
        $repo = $this->repositoryUrl('database');
        $restic->init($repo);

        $empty = $restic->stats($repo);
        self::assertInstanceOf(RepositoryStats::class, $empty);
        self::assertSame(0, $empty->snapshotsCount);
        self::assertSame(0, $empty->totalSize);

        $content = str_repeat("CREATE TABLE t (id int);\n", 3000);
        $restic->backupFromShell($repo, 'printf "%s" "$CONTENT"', ['CONTENT' => $content], 'a.sql', 'daily', 'proj-main', '2026-09-20 01:00:00');
        $restic->backupFromShell($repo, 'printf "%s" "$CONTENT"', ['CONTENT' => $content], 'b.sql', 'daily', 'proj-main', '2026-09-21 01:00:00');

        $stats = $restic->stats($repo);
        self::assertSame(2, $stats->snapshotsCount);
        self::assertGreaterThan(0, $stats->totalSize);
        // Repetitive content compresses, and the second identical dump deduplicates.
        self::assertLessThan(strlen($content), $stats->totalSize);
        self::assertGreaterThanOrEqual($stats->totalSize, $stats->totalUncompressedSize);
    }
}
