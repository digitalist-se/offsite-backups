<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\BackupClass;
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
}
