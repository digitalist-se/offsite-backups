<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Support;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Config\ConfigLoader;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Digitalist\OffsiteBackup\Restic\Restic;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real S3 service from .ddev/docker-compose.s3.yaml (or any
 * endpoint named by OFFSITE_BACKUP_TEST_S3_HOST, including the production
 * bucket) and a real restic binary. Skips when the host is not set.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $bucket;
    protected string $projectRoot;
    private ?ProcessRunner $runner = null;

    protected function setUp(): void
    {
        if (getenv('OFFSITE_BACKUP_TEST_S3_HOST') === false) {
            self::markTestSkipped('OFFSITE_BACKUP_TEST_S3_HOST not set; integration tests need an S3 endpoint');
        }
        $bin = $this->resticBin();
        $available = str_contains($bin, '/') ? is_executable($bin) : (new ProcessRunner())->run(['sh', '-c', 'command -v ' . escapeshellarg($bin)])->ok();
        if (!$available) {
            self::markTestSkipped("restic binary not available at $bin; run tools:install");
        }
        $this->bucket = 'test-' . bin2hex(random_bytes(4));
        $this->projectRoot = TempDir::create('offsite-backup-it-');
        // Production buckets are created by hand before the first run; mirror
        // that here, because restic retries a missing bucket for 15 minutes.
        $this->restic()->init($this->repositoryUrl('_bucket'));
    }

    protected function resticBin(): string
    {
        return getenv('OFFSITE_BACKUP_TEST_RESTIC_BIN') ?: 'restic';
    }

    /** @return array{host: string, key: string, secret: string, bucket: string} */
    protected function s3Env(): array
    {
        return [
            'host' => (string) getenv('OFFSITE_BACKUP_TEST_S3_HOST'),
            'key' => (string) getenv('OFFSITE_BACKUP_TEST_S3_ACCESS_KEY'),
            'secret' => (string) getenv('OFFSITE_BACKUP_TEST_S3_SECRET_KEY'),
            'bucket' => $this->bucket,
        ];
    }

    protected function repositoryUrl(string $name): string
    {
        return sprintf('s3:%s/%s/%s', $this->s3Env()['host'], $this->bucket, $name);
    }

    protected function runner(): ProcessRunner
    {
        return $this->runner ??= new ProcessRunner();
    }

    protected function restic(): Restic
    {
        $s3 = $this->s3Env();
        return new Restic($this->runner(), $this->resticBin(), 'test-password', $s3['key'], $s3['secret'], $this->projectRoot . '/backups/restic-cache');
    }

    /**
     * A Config for command tests: secrets and S3 from the test environment,
     * everything else overridable through $env (environment variable names).
     * @param array<string,string> $env
     */
    protected function config(array $env = [], ?string $configFile = null): Config
    {
        return (new ConfigLoader($this->projectRoot, $this->environment($env), $configFile))->load();
    }

    /** @param array<string,string> $env */
    protected function environment(array $env = []): Environment
    {
        $s3 = $this->s3Env();
        // Overrides win over the defaults below.
        return new Environment($env + [
            'AWS_HOST' => $s3['host'],
            'AWS_BUCKET' => $s3['bucket'],
            'AWS_ACCESS_KEY_ID' => $s3['key'],
            'AWS_SECRET_ACCESS_KEY' => $s3['secret'],
            'RESTIC_PASSWORD' => 'test-password',
            'OFFSITE_BACKUP_PROJECT' => 'proj',
            'OFFSITE_BACKUP_ENVIRONMENT' => 'main',
            'OFFSITE_BACKUP_RESTIC_BIN' => $this->resticBin(),
            'OFFSITE_BACKUP_LOG_DRUPAL' => '0',
            'PLATFORM_ENVIRONMENT_TYPE' => 'production',
        ]);
    }
}
