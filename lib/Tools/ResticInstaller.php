<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tools;

use Digitalist\OffsiteBackup\Process\ProcessRunner;

/** Downloads the pinned restic release, verifies its SHA-256 and installs it. */
final class ResticInstaller
{
    public const VERSION = '0.19.1';
    public const SHA256 = 'f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c';
    private const URL_TEMPLATE = 'https://github.com/restic/restic/releases/download/v%s/restic_%s_linux_amd64.bz2';
    private const ALLOWED_HOSTS = ['github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];

    private readonly string $url;
    private readonly string $sha256;
    private readonly string $version;
    /** @var list<string> */
    private readonly array $allowedHosts;

    /** @param list<string>|null $allowedHosts */
    public function __construct(private readonly ProcessRunner $runner, ?string $url = null, ?string $sha256 = null, ?string $version = null, ?array $allowedHosts = null)
    {
        $this->url = $url ?? self::url();
        $this->sha256 = $sha256 ?? self::SHA256;
        $this->version = $version ?? self::VERSION;
        $this->allowedHosts = $allowedHosts ?? self::ALLOWED_HOSTS;
    }

    public static function url(): string
    {
        return sprintf(self::URL_TEMPLATE, self::VERSION, self::VERSION);
    }

    /** @param callable(string):void $log */
    public function install(string $dir, bool $force, callable $log): string
    {
        $path = rtrim($dir, '/') . '/restic';
        if (!$force && is_executable($path) && $this->installedVersion($path) === $this->version) {
            $log("restic {$this->version} already installed at $path");
            return $path;
        }
        $host = parse_url($this->url, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), $this->allowedHosts, true)) {
            throw new \RuntimeException('Download host is not in the allowlist (' . implode(', ', $this->allowedHosts) . "): {$this->url}");
        }
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $archive = $path . '.bz2.part';
        $log("Downloading {$this->url}");
        $context = stream_context_create(['http' => ['follow_location' => 1, 'max_redirects' => 5, 'timeout' => 300, 'header' => "User-Agent: offsite-backups\r\n"]]);
        if (!@copy($this->url, $archive, $context)) {
            @unlink($archive);
            throw new \RuntimeException("Download failed from {$this->url}");
        }
        $actual = hash_file('sha256', $archive);
        if ($actual !== $this->sha256) {
            @unlink($archive);
            throw new \RuntimeException("SHA-256 mismatch for restic download: expected {$this->sha256}, got $actual");
        }
        $log('SHA-256 verified');
        $tmpBinary = $path . '.part';
        $result = $this->runner->run(['sh', '-c', 'bzip2 -dc "$IN" > "$OUT"'], ['IN' => $archive, 'OUT' => $tmpBinary], null, 300);
        @unlink($archive);
        if (!$result->ok()) {
            @unlink($tmpBinary);
            throw new \RuntimeException('bzip2 decompression failed: ' . $result->tail(3));
        }
        chmod($tmpBinary, 0755);
        $installed = $this->installedVersion($tmpBinary);
        if ($installed !== $this->version) {
            @unlink($tmpBinary);
            throw new \RuntimeException("Downloaded binary reports version '$installed', expected {$this->version}");
        }
        rename($tmpBinary, $path);
        $log("Installed restic {$this->version} at $path");
        return $path;
    }

    private function installedVersion(string $binary): ?string
    {
        $result = $this->runner->run([$binary, 'version'], [], null, 30);
        return $result->ok() && preg_match('/restic (\d+\.\d+\.\d+)/', $result->stdout, $m) === 1 ? $m[1] : null;
    }
}
