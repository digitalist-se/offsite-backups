<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\Duration;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function testParse(): void
    {
        self::assertSame(93600, Duration::parse('26h'));
        self::assertSame(5400, Duration::parse('90m'));
        self::assertSame(172800, Duration::parse('2d'));
        self::assertSame(30, Duration::parse('30s'));
        self::assertSame(3600, Duration::parse('3600'));
        self::assertSame(93600, Duration::parse(' 26H '));
        $this->expectException(\InvalidArgumentException::class);
        Duration::parse('soon');
    }

    public function testHuman(): void
    {
        self::assertSame('0m', Duration::human(30));
        self::assertSame('3h 12m', Duration::human(3 * 3600 + 12 * 60 + 5));
        self::assertSame('2d 1h', Duration::human(2 * 86400 + 3600 + 60));
    }
}
