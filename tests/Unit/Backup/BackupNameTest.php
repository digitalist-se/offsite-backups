<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\BackupName;
use PHPUnit\Framework\TestCase;

final class BackupNameTest extends TestCase
{
    public function testDumpNameAndDateRoundTrip(): void
    {
        $name = BackupName::dump(new \DateTimeImmutable('2026-09-25 03:00:00'), 'site', 'main');
        self::assertSame('2026-09-25-site-main.sql', $name);
        self::assertSame('2026-09-25', BackupName::dateOf($name)?->format('Y-m-d'));
        self::assertSame('2026-09-25', BackupName::dateOf('/2026-09-25-site-main.sql')?->format('Y-m-d'));
        self::assertNull(BackupName::dateOf('database.sql'));
    }
}
