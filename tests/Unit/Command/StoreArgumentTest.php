<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Command;

use Digitalist\OffsiteBackup\Command\StoreArgument;
use PHPUnit\Framework\TestCase;

final class StoreArgumentTest extends TestCase
{
    public function testParsing(): void
    {
        self::assertSame(['db'], StoreArgument::parse('db'));
        self::assertSame(['files'], StoreArgument::parse('files'));
        self::assertSame(['db', 'files'], StoreArgument::parse('all'));
        self::assertSame(['db', 'files'], StoreArgument::parse(null));
        $this->expectException(\InvalidArgumentException::class);
        StoreArgument::parse('database');
    }
}
