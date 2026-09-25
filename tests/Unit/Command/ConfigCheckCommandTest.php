<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Command;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigCheckCommandTest extends TestCase
{
    public function testReportsMissingValuesWithPlatformHints(): void
    {
        $root = TempDir::create();
        mkdir("$root/.upsun");
        touch("$root/.upsun/config.yaml");
        file_put_contents("$root/offsite-backup.yml", "project: site\ns3: {host: h, bucket: b}\n");
        $app = new Application($root, new Environment(['PLATFORM_ENVIRONMENT' => 'main']));
        $tester = new CommandTester($app->find('config:check'));
        self::assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/project\s*\|\s*site\s*\|\s*file/', $display);
        self::assertMatchesRegularExpression('/environment\s*\|\s*main\s*\|\s*env/', $display);
        self::assertMatchesRegularExpression('/repositories\.database\s*\|\s*database\s*\|\s*default/', $display);
        self::assertStringContainsString('Missing: aws_access_key_id (AWS_ACCESS_KEY_ID), aws_secret_access_key (AWS_SECRET_ACCESS_KEY), restic_password (RESTIC_PASSWORD)', $display);
        self::assertStringContainsString("upsun variable:create --level project --name env:RESTIC_PASSWORD --sensitive true --visible-build false --visible-runtime true --value '<value>'", $display);
    }

    public function testMasksSecretsAndReportsMissingBinaries(): void
    {
        $root = TempDir::create();
        $env = new Environment(['AWS_HOST' => 'h', 'AWS_BUCKET' => 'b', 'AWS_ACCESS_KEY_ID' => 'AKIA1234', 'AWS_SECRET_ACCESS_KEY' => 'topsecret', 'RESTIC_PASSWORD' => 'pw', 'PLATFORM_PROJECT' => 'p', 'PLATFORM_ENVIRONMENT' => 'e', 'OFFSITE_BACKUP_RESTIC_BIN' => '/nonexistent/restic', 'OFFSITE_BACKUP_DRUSH_BIN' => '/nonexistent/drush', 'PLATFORM_ENVIRONMENT_TYPE' => 'development']);
        $app = new Application($root, $env);
        $tester = new CommandTester($app->find('config:check'));
        self::assertSame(1, $tester->execute(['--platform' => 'platform']));
        $display = $tester->getDisplay();
        self::assertStringNotContainsString('topsecret', $display);
        self::assertStringContainsString('********', $display);
        self::assertMatchesRegularExpression('/restic binary.*not found/i', $display);
        self::assertMatchesRegularExpression('/drush.*not found/i', $display);
        self::assertStringContainsString('gated commands would be skipped (environment type "development", enabled: production)', $display);
    }
}
