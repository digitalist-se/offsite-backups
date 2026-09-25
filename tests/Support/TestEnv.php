<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Support;

/**
 * Symfony Process forwards only variables present in $_SERVER/$_ENV, so a
 * bare putenv() never reaches a child process. Set all three.
 */
final class TestEnv
{
    public static function set(string $name, string $value): void
    {
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public static function unset(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
