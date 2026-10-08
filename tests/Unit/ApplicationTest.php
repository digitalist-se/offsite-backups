<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Environment;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testExposesNameVersionAndProjectRoot(): void
    {
        $app = new Application('/tmp/project', new Environment([]));
        self::assertSame('offsite-backup', $app->getName());
        self::assertSame(Application::VERSION, $app->getVersion());
        self::assertSame('/tmp/project', $app->getProjectRoot());
    }

    public function testVersionMatchesTheReleaseLine(): void
    {
        self::assertSame('1.5.0', Application::VERSION);
    }

    public function testDetectProjectRootIsADirectory(): void
    {
        self::assertDirectoryExists(Application::detectProjectRoot());
    }
}
