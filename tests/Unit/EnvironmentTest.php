<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit;

use Digitalist\OffsiteBackup\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testEmptyStringCountsAsUnset(): void
    {
        $env = new Environment(['A' => '', 'B' => 'x']);
        self::assertNull($env->get('A'));
        self::assertSame('x', $env->get('B'));
        self::assertNull($env->get('C'));
    }

    public function testWithOverridesWithoutMutating(): void
    {
        $env = new Environment(['A' => '1']);
        $new = $env->with(['A' => '2', 'B' => '3']);
        self::assertSame('1', $env->get('A'));
        self::assertSame('2', $new->get('A'));
        self::assertSame('3', $new->get('B'));
    }
}
