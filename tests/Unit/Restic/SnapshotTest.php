<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Restic;

use Digitalist\OffsiteBackup\Restic\BackupSummary;
use Digitalist\OffsiteBackup\Restic\ResticException;
use Digitalist\OffsiteBackup\Restic\Snapshot;
use PHPUnit\Framework\TestCase;

final class SnapshotTest extends TestCase
{
    public function testParsesResticJsonIncludingNanoseconds(): void
    {
        $json = '[{"time":"2026-09-25T01:00:05.123456789+02:00","tree":"t","paths":["/2026-09-25-site-main.sql"],"hostname":"site-main","tags":["daily"],"id":"abcdef0123456789","short_id":"abcdef01","summary":{"total_bytes_processed":1234}}]';
        $snapshots = Snapshot::listFromJson($json);
        self::assertCount(1, $snapshots);
        $s = $snapshots[0];
        self::assertSame('abcdef0123456789', $s->id);
        self::assertSame('abcdef01', $s->shortId);
        self::assertSame('2026-09-24T23:00:05+00:00', $s->time->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame(['/2026-09-25-site-main.sql'], $s->paths);
        self::assertSame(['daily'], $s->tags);
        self::assertSame('site-main', $s->hostname);
        self::assertSame(1234, $s->totalBytesProcessed);
    }

    public function testMissingSummaryAndTagsAreTolerated(): void
    {
        $s = Snapshot::listFromJson('[{"time":"2026-09-25T01:00:05Z","paths":["/x"],"hostname":"h","id":"id1","short_id":"id1"}]')[0];
        self::assertNull($s->totalBytesProcessed);
        self::assertSame([], $s->tags);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ResticException::class);
        Snapshot::listFromJson('not json');
    }

    public function testSnapshotsHonourTheCallerTimeout(): void
    {
        // A shell script stands in for a stalled restic binary (external dependency); it never answers.
        $dir = \Digitalist\OffsiteBackup\Tests\Support\TempDir::create();
        file_put_contents("$dir/restic", "#!/bin/sh\nsleep 30\n");
        chmod("$dir/restic", 0755);
        $restic = new \Digitalist\OffsiteBackup\Restic\Restic(new \Digitalist\OffsiteBackup\Process\ProcessRunner(), "$dir/restic", 'pw', 'k', 's', "$dir/cache");
        $started = microtime(true);
        try {
            $restic->snapshots('s3:http://127.0.0.1:1/x/y', [], 1);
            self::fail('expected a timeout');
        } catch (ResticException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        self::assertLessThan(10, microtime(true) - $started);
    }

    public function testBackupSummaryFromJsonLines(): void
    {
        $lines = "{\"message_type\":\"status\",\"percent_done\":0.5}\n{\"message_type\":\"summary\",\"snapshot_id\":\"deadbeef\",\"total_bytes_processed\":42,\"files_new\":1}\n";
        $summary = BackupSummary::fromJsonLines($lines);
        self::assertSame('deadbeef', $summary->snapshotId);
        self::assertSame(42, $summary->totalBytesProcessed);
    }

    public function testBackupSummaryMissingThrows(): void
    {
        $this->expectException(ResticException::class);
        BackupSummary::fromJsonLines("{\"message_type\":\"status\"}\n");
    }
}
