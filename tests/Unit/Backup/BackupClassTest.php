<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\BackupClass;
use Digitalist\OffsiteBackup\Restic\Snapshot;
use PHPUnit\Framework\TestCase;

final class BackupClassTest extends TestCase
{
    public function testDayOfMonthDecidesTheClass(): void
    {
        self::assertSame('monthly', BackupClass::forDate(new \DateTimeImmutable('2026-09-01 01:00:00')));
        self::assertSame('biweekly', BackupClass::forDate(new \DateTimeImmutable('2026-09-15 23:59:59')));
        self::assertSame('daily', BackupClass::forDate(new \DateTimeImmutable('2026-09-02')));
        self::assertSame('daily', BackupClass::forDate(new \DateTimeImmutable('2026-09-30')));
        self::assertSame(['daily', 'biweekly', 'monthly'], BackupClass::ALL);
    }

    private static function snapshot(string $tag, string $time): Snapshot
    {
        return new Snapshot(md5($tag . $time), substr(md5($tag . $time), 0, 8), new \DateTimeImmutable($time), 'proj-main', ['/x.sql'], [$tag], null);
    }

    public function testTheFirstOfTheMonthIsMonthlyWhateverExists(): void
    {
        $decision = BackupClass::decide(new \DateTimeImmutable('2026-10-01 01:00:00'), [self::snapshot('monthly', '2026-10-01 00:30:00')]);
        self::assertSame(['class' => 'monthly', 'reason' => null], $decision);
    }

    public function testAMonthWithoutAMonthlyGetsOneFromTheNextRun(): void
    {
        $existing = [self::snapshot('monthly', '2026-09-01 01:00:00'), self::snapshot('daily', '2026-10-02 01:00:00')];
        $decision = BackupClass::decide(new \DateTimeImmutable('2026-10-03 01:00:00'), $existing);
        self::assertSame('monthly', $decision['class']);
        self::assertSame('no monthly snapshot for 2026-10 yet (calendar class daily)', $decision['reason']);

        $existing[] = self::snapshot('monthly', '2026-10-03 01:00:00');
        self::assertSame(['class' => 'daily', 'reason' => null], BackupClass::decide(new \DateTimeImmutable('2026-10-04 01:00:00'), $existing));
    }

    public function testOnTheFifteenthAMissingMonthlyBeatsTheBiweekly(): void
    {
        $now = new \DateTimeImmutable('2026-10-15 01:00:00');
        self::assertSame('monthly', BackupClass::decide($now, [])['class']);
        self::assertSame(['class' => 'biweekly', 'reason' => null], BackupClass::decide($now, [self::snapshot('monthly', '2026-10-01 01:00:00')]));
    }

    public function testAfterTheFifteenthAMissingBiweeklyIsCaughtUp(): void
    {
        $monthly = self::snapshot('monthly', '2026-10-01 01:00:00');
        $decision = BackupClass::decide(new \DateTimeImmutable('2026-10-20 01:00:00'), [$monthly]);
        self::assertSame('biweekly', $decision['class']);
        self::assertSame('no biweekly snapshot for 2026-10 yet (calendar class daily)', $decision['reason']);

        $withBiweekly = [$monthly, self::snapshot('biweekly', '2026-10-16 01:00:00')];
        self::assertSame(['class' => 'daily', 'reason' => null], BackupClass::decide(new \DateTimeImmutable('2026-10-20 01:00:00'), $withBiweekly));
        // Before the 15th there is nothing to catch up for the biweekly class.
        self::assertSame(['class' => 'daily', 'reason' => null], BackupClass::decide(new \DateTimeImmutable('2026-10-10 01:00:00'), [$monthly]));
    }

    public function testSnapshotsOfOtherMonthsOrClassesDoNotCount(): void
    {
        $existing = [self::snapshot('daily', '2026-10-01 01:00:00'), self::snapshot('biweekly', '2026-10-01 01:00:00'), self::snapshot('monthly', '2026-09-30 23:00:00')];
        self::assertSame('monthly', BackupClass::decide(new \DateTimeImmutable('2026-10-05 01:00:00'), $existing)['class']);
    }

    public function testTheMonthIsJudgedInTheRunsTimezone(): void
    {
        // 23:30 UTC on 30 September is already 1 October in Stockholm: that monthly counts for October there.
        $existing = [self::snapshot('monthly', '2026-09-30 23:30:00+00:00')];
        $stockholm = new \DateTimeImmutable('2026-10-05 03:00:00', new \DateTimeZone('Europe/Stockholm'));
        self::assertSame('daily', BackupClass::decide($stockholm, $existing)['class']);
        $utc = new \DateTimeImmutable('2026-10-05 01:00:00', new \DateTimeZone('UTC'));
        self::assertSame('monthly', BackupClass::decide($utc, $existing)['class']);
    }
}
