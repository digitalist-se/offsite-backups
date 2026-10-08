<?php

declare(strict_types=1);

namespace Drupal\offsite_backup;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Command\DbListCommand;
use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Config\ConfigLoader;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Digitalist\OffsiteBackup\Restic\Coverage;
use Digitalist\OffsiteBackup\Restic\RepositoryStats;
use Digitalist\OffsiteBackup\Restic\Restic;
use Digitalist\OffsiteBackup\Restic\Snapshot;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * What the repositories hold, read through restic and cached per store.
 */
final class RepositoryInsight {

  public const STORES = [Config::STORE_DB => 'Database', Config::STORE_FILES => 'Files'];

  private const SNAPSHOTS_TTL = 300;
  private const STATS_TTL = 3600;
  // A web request must not wait for a stalled endpoint.
  private const TIMEOUT = 30;

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Own snapshots of a store, newest first.
   *
   * @return array{snapshots: list<\Digitalist\OffsiteBackup\Restic\Snapshot>, error: string|null}
   */
  public function snapshots(string $store): array {
    return $this->cached("offsite_backup.snapshots.$store", self::SNAPSHOTS_TTL, function () use ($store): array {
      $config = $this->config();
      $restic = Restic::fromConfig($config, new ProcessRunner());
      $repo = $config->repositoryUrl($store);
      $restic->requireRepository($repo, self::TIMEOUT);
      return ['snapshots' => $restic->snapshots($repo, ['host' => $config->resticHost()], self::TIMEOUT), 'error' => NULL];
    }, ['snapshots' => []]);
  }

  /**
   * The snapshots of a store as table rows.
   *
   * @return array{rows: list<array{date: string, class: string, name: string, snapshot: string, size: string}>, error: string|null}
   */
  public function listing(string $store): array {
    $result = $this->snapshots($store);
    return [
      'rows' => array_map(static fn (Snapshot $s): array => self::rowFor($s, $store), $result['snapshots']),
      'error' => $result['error'],
    ];
  }

  /**
   * One row per store: count, storage used, restore window and retention coverage.
   *
   * @return array{rows: list<array{label: string, repository: string, snapshots: string, size: string, oldest: string, newest: string, coverage: string, note: string}>, error: string|null}
   */
  public function overview(): array {
    try {
      $config = $this->config();
    }
    catch (\Throwable $e) {
      return ['rows' => [], 'error' => $e->getMessage()];
    }
    $rows = [];
    foreach (self::STORES as $store => $label) {
      $listed = $this->snapshots($store);
      $stats = $this->stats($store);
      $coverage = Coverage::of($listed['snapshots'], $config->keepCounts());
      $notes = array_filter([$listed['error'], $stats['error']]);
      $rows[] = [
        'label' => $label,
        'repository' => $store === Config::STORE_DB ? $config->dbRepo : $config->filesRepo,
        'snapshots' => $listed['error'] === NULL ? (string) count($listed['snapshots']) : '?',
        'size' => $stats['stats'] instanceof RepositoryStats ? DbListCommand::bytes($stats['stats']->totalSize) : '?',
        'oldest' => $coverage->oldest?->format('Y-m-d H:i') ?? '-',
        'newest' => $coverage->newest?->format('Y-m-d H:i') ?? '-',
        'coverage' => $listed['error'] === NULL ? $coverage->describe() : '-',
        'note' => implode(' ', $notes),
      ];
    }
    return ['rows' => $rows, 'error' => NULL];
  }

  /**
   * One listing row: dumps are named by their file, files snapshots by every path.
   *
   * @return array{date: string, class: string, name: string, snapshot: string, size: string}
   */
  public static function rowFor(Snapshot $snapshot, string $store): array {
    return [
      'date' => $snapshot->time->format('Y-m-d H:i'),
      'class' => $snapshot->tags[0] ?? '',
      'name' => $store === Config::STORE_DB ? ltrim($snapshot->paths[0] ?? '', '/') : implode(', ', $snapshot->paths),
      'snapshot' => $snapshot->shortId,
      'size' => DbListCommand::bytes($snapshot->totalBytesProcessed),
    ];
  }

  /**
   * Storage used by a store's repository, compressed and deduplicated.
   *
   * @return array{stats: \Digitalist\OffsiteBackup\Restic\RepositoryStats|null, error: string|null}
   */
  private function stats(string $store): array {
    return $this->cached("offsite_backup.stats.$store", self::STATS_TTL, function () use ($store): array {
      $config = $this->config();
      $restic = Restic::fromConfig($config, new ProcessRunner());
      $repo = $config->repositoryUrl($store);
      $restic->requireRepository($repo, self::TIMEOUT);
      return ['stats' => $restic->stats($repo, self::TIMEOUT), 'error' => NULL];
    }, ['stats' => NULL]);
  }

  private function config(): Config {
    return (new ConfigLoader(Application::detectProjectRoot(), Environment::fromGlobals()))->load();
  }

  /**
   * Runs $read once per TTL; a failure is cached too, as $empty plus the message.
   *
   * @param callable(): array<string, mixed> $read
   * @param array<string, mixed> $empty
   *
   * @return array<string, mixed>
   */
  private function cached(string $cid, int $ttl, callable $read, array $empty): array {
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }
    try {
      $result = $read();
    }
    catch (\Throwable $e) {
      $result = $empty + ['error' => $e->getMessage()];
    }
    $this->cache->set($cid, $result, $this->time->getRequestTime() + $ttl);
    return $result;
  }

}
