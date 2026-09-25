<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Integration;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Tests\Support\IntegrationTestCase;
use Digitalist\OffsiteBackup\Tests\Support\TestEnv;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandTest extends IntegrationTestCase
{
    public function testInitCreatesTheBucketWhenItDoesNotExist(): void
    {
        $bucket = 'fresh-' . bin2hex(random_bytes(4));
        $app = new Application($this->projectRoot, $this->environment(['AWS_BUCKET' => $bucket]));
        $init = new CommandTester($app->find('init'));
        $started = microtime(true);
        self::assertSame(0, $init->execute(['store' => 'db']), $init->getDisplay());
        self::assertLessThan(60, microtime(true) - $started);
        self::assertStringContainsString("Initialised repository s3:{$this->s3Env()['host']}/$bucket/database", $init->getDisplay());
    }

    public function testInitIsIdempotentAndConfigCheckPassesAfterwards(): void
    {
        $app = new Application($this->projectRoot, $this->environment(['OFFSITE_BACKUP_DRUSH_BIN' => __DIR__ . '/../Support/fake-drush.php']));
        $init = new CommandTester($app->find('init'));
        self::assertSame(0, $init->execute([]), $init->getDisplay());
        self::assertStringContainsString('Initialised repository ' . $this->repositoryUrl('database'), $init->getDisplay());
        self::assertStringContainsString('Initialised repository ' . $this->repositoryUrl('public-files'), $init->getDisplay());
        self::assertSame(0, $init->execute(['store' => 'db']));
        self::assertStringContainsString('already initialised', $init->getDisplay());
        self::assertTrue($this->restic()->repositoryExists($this->repositoryUrl('database')));

        $check = new CommandTester($app->find('config:check'));
        TestEnv::set('FAKE_DRUSH_OUT', $this->projectRoot . '/drush.log');
        TestEnv::unset('FAKE_DRUSH_FAIL');
        self::assertSame(0, $check->execute([]), $check->getDisplay());
        self::assertStringContainsString('repository db: ok', $check->getDisplay());
        self::assertStringContainsString('repository files: ok', $check->getDisplay());
        self::assertStringContainsString('gated commands would run (environment type "production")', $check->getDisplay());
    }
}
