<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Tools;

use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use Digitalist\OffsiteBackup\Tools\ResticInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ResticInstallerTest extends TestCase
{
    private string $www;
    private Process $server;
    private int $port;
    private string $sha;

    protected function setUp(): void
    {
        // A local HTTP server replaces the GitHub download (external network);
        // the served "binary" is a script that answers `restic version` like the real one.
        $this->www = TempDir::create();
        file_put_contents($this->www . '/restic', "#!/bin/sh\necho 'restic 0.19.1 compiled with go1.24 on linux/amd64'\n");
        (new ProcessRunner())->run(['bzip2', '-kf', $this->www . '/restic']);
        $this->sha = (string) hash_file('sha256', $this->www . '/restic.bz2');
        $this->port = random_int(20000, 40000);
        $this->server = new Process(['php', '-S', "127.0.0.1:{$this->port}", '-t', $this->www]);
        $this->server->start();
        usleep(500000);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    private function installer(?string $sha = null): ResticInstaller
    {
        return new ResticInstaller(new ProcessRunner(), "http://127.0.0.1:{$this->port}/restic.bz2", $sha ?? $this->sha, '0.19.1', ['127.0.0.1']);
    }

    public function testDownloadsVerifiesAndSkipsWhenAlreadyInstalled(): void
    {
        $dir = TempDir::create();
        $log = [];
        $path = $this->installer()->install($dir, false, static function (string $m) use (&$log): void { $log[] = $m; });
        self::assertSame("$dir/restic", $path);
        self::assertTrue(is_executable($path));
        self::assertStringContainsString('restic 0.19.1', (new ProcessRunner())->run([$path, 'version'])->stdout);
        self::assertStringContainsString('SHA-256 verified', implode("\n", $log));

        $log = [];
        $this->installer()->install($dir, false, static function (string $m) use (&$log): void { $log[] = $m; });
        self::assertStringContainsString('already installed', implode("\n", $log));
    }

    public function testChecksumMismatchLeavesNothingBehind(): void
    {
        $dir = TempDir::create();
        try {
            $this->installer(str_repeat('0', 64))->install($dir, false, static function (string $m): void {});
            self::fail('expected checksum failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SHA-256', $e->getMessage());
        }
        self::assertFileDoesNotExist("$dir/restic");
        self::assertSame([], glob("$dir/*") ?: []);
    }

    public function testDisallowedHostIsRefused(): void
    {
        $installer = new ResticInstaller(new ProcessRunner(), 'https://evil.example.com/restic.bz2', $this->sha, '0.19.1');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('allowlist');
        $installer->install(TempDir::create(), false, static function (string $m): void {});
    }

    public function testPinnedConstantsLookRight(): void
    {
        self::assertSame('0.19.1', ResticInstaller::VERSION);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ResticInstaller::SHA256);
        self::assertStringContainsString('restic_0.19.1_linux_amd64.bz2', ResticInstaller::url());
    }
}
