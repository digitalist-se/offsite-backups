<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Config;

use Digitalist\OffsiteBackup\Config\ConfigException;
use Digitalist\OffsiteBackup\Config\ConfigLoader;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::create();
    }

    /** @return array<string,string> */
    private function secrets(): array
    {
        return ['AWS_ACCESS_KEY_ID' => 'key', 'AWS_SECRET_ACCESS_KEY' => 'secret', 'RESTIC_PASSWORD' => 'pw'];
    }

    public function testEnvOnlyConfigurationLoadsWithDefaults(): void
    {
        $env = new Environment($this->secrets() + ['AWS_HOST' => 'backups.example.com', 'AWS_BUCKET' => 'site', 'PLATFORM_PROJECT' => 'abc', 'PLATFORM_ENVIRONMENT' => 'main']);
        $config = (new ConfigLoader($this->root, $env))->load();

        self::assertSame('abc', $config->project);
        self::assertSame('main', $config->environment);
        self::assertSame('abc-main', $config->resticHost());
        self::assertSame('s3:https://backups.example.com/site/database', $config->repositoryUrl('db'));
        self::assertSame('s3:https://backups.example.com/site/public-files', $config->repositoryUrl('files'));
        self::assertSame(['production'], $config->environmentTypes);
        self::assertSame(['cache', 'cache_*'], $config->dbStructureTables);
        self::assertSame('{path}/css', $config->filesExcludes[0], 'the default excludes are anchored to each files path');
        self::assertSame(7, $config->keepDaily);
        self::assertSame($this->root . '/backups', $config->localDir);
        self::assertSame($this->root . '/backups/restic-cache', $config->resticCacheDir);
        self::assertSame('30m', $config->resticRetryLock);
        self::assertSame(7200, $config->resticBackupTimeout);
        self::assertTrue($config->logDrupal);
        self::assertNull($config->slackWebhookUrl);
    }

    public function testFileValuesOverrideDefaultsAndEnvOverridesFile(): void
    {
        file_put_contents($this->root . '/offsite-backup.yml', "project: site\ns3:\n  host: backups.example.com/\n  bucket: site\nfiles:\n  paths: [web/sites/default/files, private]\nretention: {daily: 3}\nrestic:\n  bin: /app/.global/bin/restic\n  retry_lock: ''\n  backup_timeout: 600\n");
        $env = new Environment($this->secrets() + ['PLATFORM_ENVIRONMENT' => 'main', 'OFFSITE_BACKUP_KEEP_DAILY' => '9']);
        $loader = new ConfigLoader($this->root, $env);
        $config = $loader->load();
        $resolved = $loader->resolve();

        self::assertSame('site', $config->project);
        self::assertSame('https://backups.example.com', $config->s3Host);
        self::assertSame(['web/sites/default/files', 'private'], $config->filesPaths);
        self::assertSame(9, $config->keepDaily, 'env wins over file');
        self::assertSame('/app/.global/bin/restic', $config->resticBin);
        self::assertSame('', $config->resticRetryLock, 'an empty file value disables --retry-lock');
        self::assertSame(600, $config->resticBackupTimeout);
        self::assertSame('file', $resolved->sources['project']);
        self::assertSame('env', $resolved->sources['retention.daily']);
        self::assertSame('default', $resolved->sources['retention.monthly']);
        self::assertSame($this->root . '/offsite-backup.yml', $resolved->configFile);
    }

    public function testEnvironmentPrefersTheBranchNameOverThePlatformEnvironmentId(): void
    {
        // On Upsun PLATFORM_ENVIRONMENT is an id such as "main-bvxea6i"; PLATFORM_BRANCH is "main".
        $env = new Environment($this->secrets() + ['AWS_HOST' => 'h', 'AWS_BUCKET' => 'b', 'PLATFORM_PROJECT' => 'p', 'PLATFORM_BRANCH' => 'main', 'PLATFORM_ENVIRONMENT' => 'main-bvxea6i']);
        $config = (new ConfigLoader($this->root, $env))->load();
        self::assertSame('main', $config->environment);
        self::assertSame('p-main', $config->resticHost());

        $env = new Environment($this->secrets() + ['AWS_HOST' => 'h', 'AWS_BUCKET' => 'b', 'PLATFORM_PROJECT' => 'p', 'PLATFORM_ENVIRONMENT' => 'stage']);
        self::assertSame('stage', (new ConfigLoader($this->root, $env))->load()->environment, 'Platform.sh without PLATFORM_BRANCH falls back');
    }

    public function testMissingRequiredValuesAreReportedTogether(): void
    {
        $loader = new ConfigLoader($this->root, new Environment([]));
        $resolved = $loader->resolve();
        // Order follows the settings table.
        self::assertSame(['project', 'environment', 's3.host', 's3.bucket', 'aws_access_key_id', 'aws_secret_access_key', 'restic_password'], $resolved->missing);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/s3\.host \(AWS_HOST\)/');
        $loader->load();
    }

    public function testSecretInFileIsRejected(): void
    {
        file_put_contents($this->root . '/offsite-backup.yml', "s3: {host: h, bucket: b}\nrestic_password: nope\n");
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('restic_password');
        (new ConfigLoader($this->root, new Environment($this->secrets())))->resolve();
    }

    public function testUnknownKeyInFileIsRejected(): void
    {
        file_put_contents($this->root . '/offsite-backup.yml', "retentoin: {daily: 3}\n");
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('retentoin');
        (new ConfigLoader($this->root, new Environment($this->secrets())))->resolve();
    }

    public function testListsAcceptCommaSeparatedEnvAndYamlLists(): void
    {
        $env = new Environment($this->secrets() + ['AWS_HOST' => 'h', 'AWS_BUCKET' => 'b', 'PLATFORM_PROJECT' => 'p', 'PLATFORM_ENVIRONMENT' => 'e', 'OFFSITE_BACKUP_ENVIRONMENT_TYPES' => 'production, staging', 'OFFSITE_BACKUP_DB_STRUCTURE_TABLES' => '']);
        $config = (new ConfigLoader($this->root, $env))->load();
        self::assertSame(['production', 'staging'], $config->environmentTypes);
        self::assertSame(['cache', 'cache_*'], $config->dbStructureTables, 'an empty env value counts as unset, so the default applies');
        self::assertTrue($config->isEnabledEnvironmentType('staging'));
        self::assertFalse($config->isEnabledEnvironmentType(null));
    }

    public function testEmptyListInFileDumpsEverything(): void
    {
        file_put_contents($this->root . '/offsite-backup.yml', "database: {structure_tables: []}\n");
        $env = new Environment($this->secrets() + ['AWS_HOST' => 'h', 'AWS_BUCKET' => 'b', 'PLATFORM_PROJECT' => 'p', 'PLATFORM_ENVIRONMENT' => 'e']);
        $config = (new ConfigLoader($this->root, $env))->load();
        self::assertSame([], $config->dbStructureTables);
    }

    public function testHostNormalisation(): void
    {
        foreach (['h.example.com', 'http://h.example.com', 'https://h.example.com/', 'H.EXAMPLE.COM'] as $raw) {
            $env = new Environment($this->secrets() + ['AWS_HOST' => $raw, 'AWS_BUCKET' => 'b', 'PLATFORM_PROJECT' => 'p', 'PLATFORM_ENVIRONMENT' => 'e']);
            $config = (new ConfigLoader($this->root, $env))->load();
            self::assertSame($raw === 'http://h.example.com' ? 'http://h.example.com' : 'https://h.example.com', $config->s3Host, $raw);
        }
    }

    public function testExplicitConfigPathAndBoolParsing(): void
    {
        $file = $this->root . '/custom.yml';
        file_put_contents($file, "s3: {host: h, bucket: b}\nproject: p\nenvironment: e\nlog: {drupal: false}\n");
        $config = (new ConfigLoader($this->root, new Environment($this->secrets()), $file))->load();
        self::assertFalse($config->logDrupal);
        $config2 = (new ConfigLoader($this->root, new Environment($this->secrets() + ['OFFSITE_BACKUP_LOG_DRUPAL' => '0']), $file))->load();
        self::assertFalse($config2->logDrupal);
    }

    public function testMissingExplicitConfigPathFails(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not found');
        (new ConfigLoader($this->root, new Environment($this->secrets()), $this->root . '/nope.yml'))->resolve();
    }
}
