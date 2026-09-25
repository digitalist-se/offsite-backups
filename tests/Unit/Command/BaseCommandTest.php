<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Command;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Command\BaseCommand;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BaseCommandTest extends TestCase
{
    public function testEnvStdinRejectsInvalidJson(): void
    {
        $app = new Application(TempDir::create(), new Environment([]));
        $tester = new CommandTester($app->find('init'));
        $tester->setInputs(['not json']);
        $code = $tester->execute(['--env-stdin' => true]);
        self::assertSame(1, $code);
        self::assertStringContainsString('--env-stdin expects a JSON object', $tester->getDisplay());
    }

    public function testEnvStdinRejectsNonObject(): void
    {
        $app = new Application(TempDir::create(), new Environment([]));
        $tester = new CommandTester($app->find('init'));
        $tester->setInputs(['[1,2]']);
        self::assertSame(1, $tester->execute(['--env-stdin' => true]));
        self::assertStringContainsString('--env-stdin expects a JSON object', $tester->getDisplay());
    }

    public function testMissingConfigurationExitsOneWithTheList(): void
    {
        $app = new Application(TempDir::create(), new Environment(['AWS_HOST' => 'h']));
        $tester = new CommandTester($app->find('init'));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Missing required configuration:', $tester->getDisplay());
        self::assertStringContainsString('s3.bucket (AWS_BUCKET)', $tester->getDisplay());
    }

    public function testReadEnvFromStdinCastsScalars(): void
    {
        self::assertSame(['A' => '1', 'B' => 'x', 'C' => ''], BaseCommand::readEnvFromStdin('{"A": 1, "B": "x", "C": null}'));
    }

    public function testStateKeyDerivation(): void
    {
        self::assertSame('offsite_backup.run.db_backup', BaseCommand::stateKeyFor('db:backup'));
        self::assertSame('offsite_backup.run.config_check', BaseCommand::stateKeyFor('config:check'));
    }

    public function testApplicationListsAllPhaseOneCommands(): void
    {
        $app = new Application('/tmp', new Environment([]));
        $names = array_keys($app->all());
        foreach (['init', 'config:check'] as $expected) {
            self::assertContains($expected, $names);
        }
    }
}
