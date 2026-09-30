<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Restic;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Process\ProcessResult;
use Digitalist\OffsiteBackup\Process\ProcessRunner;

/** Every restic invocation goes through here: argument arrays and an explicit environment. */
final class Restic
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $bin,
        private readonly string $password,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $cacheDir,
    ) {}

    public static function fromConfig(Config $config, ProcessRunner $runner): self
    {
        return new self($runner, $config->resticBin, $config->resticPassword, $config->awsAccessKeyId, $config->awsSecretAccessKey, $config->resticCacheDir);
    }

    /** @return array<string,string> */
    public function environment(string $repositoryUrl): array
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
        return [
            'RESTIC_REPOSITORY' => $repositoryUrl,
            'RESTIC_PASSWORD' => $this->password,
            'AWS_ACCESS_KEY_ID' => $this->accessKeyId,
            'AWS_SECRET_ACCESS_KEY' => $this->secretAccessKey,
            'RESTIC_CACHE_DIR' => $this->cacheDir,
        ];
    }

    /** @param list<string> $args */
    public function tryRun(string $repositoryUrl, array $args, ?callable $onStdout = null, ?int $timeout = 7200, ?callable $abortOnStderr = null): ProcessResult
    {
        return $this->runner->run(array_merge([$this->bin], $args), $this->environment($repositoryUrl), null, $timeout, $onStdout, null, $abortOnStderr);
    }

    /** @param list<string> $args */
    public function run(string $repositoryUrl, array $args, ?callable $onStdout = null, ?int $timeout = 7200): ProcessResult
    {
        $result = $this->tryRun($repositoryUrl, $args, $onStdout, $timeout);
        if (!$result->ok()) {
            throw new ResticException(sprintf('restic %s failed (exit %d): %s', $args[0] ?? '', $result->exitCode, $result->tail(10)));
        }
        return $result;
    }

    public function version(): string
    {
        $result = $this->runner->run([$this->bin, 'version'], [], null, 30);
        if (!$result->ok() || preg_match('/restic (\d+\.\d+\.\d+)/', $result->stdout, $m) !== 1) {
            throw new ResticException("Cannot determine restic version from '{$this->bin}': " . $result->tail(3));
        }
        return $m[1];
    }

    private const MISSING_PATTERN = '/Is there a repository at the following location|bucket does not exist|NoSuchBucket/i';

    /**
     * False when the repository, or the bucket holding it, does not exist.
     * restic retries a missing bucket with backoff for up to 15 minutes, so the
     * probe is aborted as soon as restic reports it, and capped at one minute.
     */
    public function repositoryExists(string $repositoryUrl): bool
    {
        $result = $this->tryRun($repositoryUrl, ['cat', 'config'], null, 60, static fn (string $stderr): bool => preg_match(self::MISSING_PATTERN, $stderr) === 1);
        if ($result->ok()) {
            return true;
        }
        if (preg_match(self::MISSING_PATTERN, $result->stderr) === 1) {
            return false;
        }
        throw new ResticException('Cannot access repository ' . $repositoryUrl . ': ' . $result->tail(5));
    }

    /** Guard for every command but init: fail in seconds instead of letting restic retry for 15 minutes. */
    public function requireRepository(string $repositoryUrl): void
    {
        if (!$this->repositoryExists($repositoryUrl)) {
            throw new ResticException("Repository $repositoryUrl does not exist (bucket or repository missing); run `offsite-backup init`");
        }
    }

    public function init(string $repositoryUrl): void
    {
        $this->run($repositoryUrl, ['init'], null, 300);
    }

    /**
     * Snapshots newest first. `latest` limits the result client-side, because
     * restic's own --latest is per host *and path* and dump paths differ daily.
     *
     * @param array{tag?: string, host?: string, latest?: int, path?: string} $filter
     * @return list<Snapshot>
     */
    public function snapshots(string $repositoryUrl, array $filter = [], int $timeout = 600): array
    {
        $args = ['snapshots', '--json'];
        if (isset($filter['tag'])) {
            $args[] = '--tag';
            $args[] = $filter['tag'];
        }
        if (isset($filter['host'])) {
            $args[] = '--host';
            $args[] = $filter['host'];
        }
        if (isset($filter['path'])) {
            $args[] = '--path';
            $args[] = $filter['path'];
        }
        $result = $this->run($repositoryUrl, $args, null, $timeout);
        $snapshots = Snapshot::listFromJson(trim($result->stdout) === '' ? '[]' : $result->stdout);
        usort($snapshots, static fn (Snapshot $a, Snapshot $b): int => $b->time <=> $a->time);
        if (isset($filter['latest'])) {
            $snapshots = array_slice($snapshots, 0, max(0, (int) $filter['latest']));
        }
        return $snapshots;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     */
    public function backupPaths(string $repositoryUrl, array $paths, array $excludes, string $tag, string $host, ?string $time = null): BackupSummary
    {
        $args = array_merge(['backup', '--json', '--tag', $tag, '--host', $host], $paths);
        foreach ($excludes as $exclude) {
            $args[] = '--exclude';
            $args[] = $exclude;
        }
        if ($time !== null) {
            $args[] = '--time';
            $args[] = $time;
        }
        return BackupSummary::fromJsonLines($this->run($repositoryUrl, $args)->stdout);
    }

    /**
     * Pipes the stdout of a shell producer into `restic backup --stdin`.
     * $producer is a shell snippet using "$VAR" placeholders resolved from $producerEnv.
     * @param array<string,string> $producerEnv
     */
    public function backupFromShell(string $repositoryUrl, string $producer, array $producerEnv, string $stdinFilename, string $tag, string $host, ?string $time = null): BackupSummary
    {
        $timeArgs = $time !== null ? ' --time "$OB_TIME"' : '';
        $command = 'set -o pipefail; ' . $producer . ' | "$OB_RESTIC_BIN" backup --json --stdin --stdin-filename "$OB_NAME" --tag "$OB_TAG" --host "$OB_HOST"' . $timeArgs;
        $env = $producerEnv + $this->environment($repositoryUrl) + ['OB_RESTIC_BIN' => $this->bin, 'OB_NAME' => $stdinFilename, 'OB_TAG' => $tag, 'OB_HOST' => $host, 'OB_TIME' => (string) $time];
        $result = $this->runner->run(['bash', '-c', $command], $env, null, 7200);
        if (!$result->ok()) {
            throw new ResticException(sprintf('restic backup --stdin failed (exit %d): %s', $result->exitCode, $result->tail(10)));
        }
        return BackupSummary::fromJsonLines($result->stdout);
    }

    /**
     * Returns how many snapshots were (or, on dry run, would be) removed.
     * Grouping is by host and tags: restic's default also groups by path, and
     * dump paths differ per day, which would keep every dump forever.
     */
    public function forget(string $repositoryUrl, string $tag, string $host, int $keepLast, bool $dryRun): int
    {
        $args = ['forget', '--json', '--tag', $tag, '--host', $host, '--group-by', 'host,tags', '--keep-last', (string) $keepLast];
        if ($dryRun) {
            $args[] = '--dry-run';
        }
        $result = $this->run($repositoryUrl, $args, null, 600);
        $data = json_decode(trim($result->stdout) === '' ? '[]' : $result->stdout, true);
        if (!is_array($data)) {
            throw new ResticException('restic forget returned unexpected output: ' . $result->tail(5));
        }
        $removed = 0;
        foreach ($data as $group) {
            $removed += is_array($group['remove'] ?? null) ? count($group['remove']) : 0;
        }
        return $removed;
    }

    public function prune(string $repositoryUrl): void
    {
        $this->run($repositoryUrl, ['prune']);
    }

    public function check(string $repositoryUrl, string $readDataSubset): void
    {
        $this->run($repositoryUrl, ['check', '--read-data-subset', $readDataSubset]);
    }

    /** Streams `restic dump` into a gzip file; returns the number of uncompressed bytes. */
    public function dumpToGzip(string $repositoryUrl, string $snapshotId, string $path, string $targetFile): int
    {
        $gz = gzopen($targetFile, 'wb6');
        if ($gz === false) {
            throw new ResticException("Cannot open $targetFile for writing");
        }
        $bytes = 0;
        try {
            $result = $this->tryRun($repositoryUrl, ['dump', $snapshotId, $path], static function (string $chunk) use ($gz, &$bytes): void {
                gzwrite($gz, $chunk);
                $bytes += strlen($chunk);
            });
        } finally {
            gzclose($gz);
        }
        if (!$result->ok()) {
            @unlink($targetFile);
            throw new ResticException(sprintf('restic dump failed (exit %d): %s', $result->exitCode, $result->tail(10)));
        }
        return $bytes;
    }

    public function restore(string $repositoryUrl, string $snapshotId, string $targetDir, ?string $includePath): void
    {
        $args = ['restore', $snapshotId, '--target', $targetDir];
        if ($includePath !== null) {
            $args[] = '--include';
            $args[] = rtrim($includePath, '/') . '/**';
            $args[] = '--include';
            $args[] = $includePath;
        }
        $this->run($repositoryUrl, $args);
    }
}
