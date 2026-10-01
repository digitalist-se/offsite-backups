<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Restic;

use Digitalist\OffsiteBackup\Restic\Coverage;
use Digitalist\OffsiteBackup\Restic\Snapshot;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    private const KEEP = ['daily' => 7, 'biweekly' => 1, 'monthly' => 12];

    private static function snapshot(string $time, string $tag): Snapshot
    {
        return new Snapshot(md5($time), substr(md5($time), 0, 8), new \DateTimeImmutable($time), 'proj-main', ['/x.sql'], $tag === '' ? [] : [$tag], 10);
    }

    public function testCountsEveryClassAgainstRetentionAndFindsTheRange(): void
    {
        $coverage = Coverage::of([
            self::snapshot('2026-10-01 01:00:00', 'monthly'),
            self::snapshot('2026-09-30 01:00:00', 'daily'),
            self::snapshot('2026-09-29 01:00:00', 'daily'),
            self::snapshot('2026-09-28 01:00:00', ''),
        ], self::KEEP);

        self::assertSame(['daily' => 2, 'biweekly' => 0, 'monthly' => 1], $coverage->counts);
        self::assertSame('2026-09-28', $coverage->oldest?->format('Y-m-d'));
        self::assertSame('2026-10-01', $coverage->newest?->format('Y-m-d'));
        self::assertSame('daily 2 of 7, biweekly 0 of 1, monthly 1 of 12', $coverage->describe());
    }

    public function testEmptyRepositoryHasNoRange(): void
    {
        $coverage = Coverage::of([], self::KEEP);
        self::assertSame(['daily' => 0, 'biweekly' => 0, 'monthly' => 0], $coverage->counts);
        self::assertNull($coverage->oldest);
        self::assertNull($coverage->newest);
        self::assertSame('daily 0 of 7, biweekly 0 of 1, monthly 0 of 12', $coverage->describe());
    }
}
