<?php

declare(strict_types=1);

namespace Drupal\offsite_backup;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Backup\Duration;
use Digitalist\OffsiteBackup\Command\DbListCommand;
use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Config\ConfigLoader;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Digitalist\OffsiteBackup\Restic\Restic;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Reads what the offsite-backup CLI left in State and, on demand, the repository.
 */
final class BackupStatus {

  public const JOBS = [
    'db_backup' => 'Database backup',
    'files_backup' => 'Files backup',
    'prune' => 'Retention',
    'check' => 'Repository check',
  ];

  private const DEFAULT_MAX_AGE = 93600;
  private const DUMPS_CACHE_ID = 'offsite_backup.dumps';
  private const DUMPS_CACHE_TTL = 300;

  public function __construct(
    private readonly StateInterface $state,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The last stored run per job, or NULL when none was recorded.
   *
   * @return array<string, array<string, mixed>|null>
   */
  public function lastRuns(): array {
    $runs = [];
    foreach (array_keys(self::JOBS) as $job) {
      $value = $this->state->get("offsite_backup.run.$job");
      $runs[$job] = is_array($value) ? $value : NULL;
    }
    return $runs;
  }

  /**
   * The site's status_max_age, from offsite-backup.yml or the default.
   */
  public function maxAgeSeconds(): int {
    try {
      $resolved = (new ConfigLoader(Application::detectProjectRoot(), Environment::fromGlobals()))->resolve();
      return Duration::parse((string) $resolved->values['status_max_age']);
    }
    catch (\Throwable) {
      return self::DEFAULT_MAX_AGE;
    }
  }

  /**
   * Freshness of the last successful database and files backups.
   *
   * @return array{level: string, db_age: int|null, files_age: int|null, summary: string}
   */
  public function freshness(): array {
    $runs = $this->lastRuns();
    $dbAge = $this->successAge($runs['db_backup']);
    $filesAge = $this->successAge($runs['files_backup']);
    if ($dbAge === NULL && $filesAge === NULL) {
      return ['level' => 'unknown', 'db_age' => NULL, 'files_age' => NULL, 'summary' => 'No offsite backup run recorded yet.'];
    }
    $max = $this->maxAgeSeconds();
    // A store with no successful run yet is a warning; only a known age can be an error.
    $known = array_filter([$dbAge, $filesAge], static fn (?int $age): bool => $age !== NULL);
    $worst = max($known);
    $level = $worst > 2 * $max ? 'error' : ($worst > $max || count($known) < 2 ? 'warning' : 'ok');
    $summary = sprintf('Database %s, files %s.', $this->describe($dbAge), $this->describe($filesAge));
    return ['level' => $level, 'db_age' => $dbAge, 'files_age' => $filesAge, 'summary' => $summary];
  }

  /**
   * The status-report entry without a severity: the hooks map 'level' to
   * the constant or enum their Drupal version expects.
   *
   * @return array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, value: string|\Drupal\Core\StringTranslation\TranslatableMarkup, description: \Drupal\Core\StringTranslation\TranslatableMarkup, level: string}
   */
  public function requirement(): array {
    $freshness = $this->freshness();
    return [
      'title' => new TranslatableMarkup('Offsite backups'),
      'value' => $freshness['level'] === 'unknown' ? new TranslatableMarkup('No offsite backup run recorded yet.') : $freshness['summary'],
      'description' => new TranslatableMarkup('See the <a href=":url">offsite backups report</a>.', [':url' => Url::fromRoute('offsite_backup.report')->toString()]),
      'level' => $freshness['level'],
    ];
  }

  /**
   * Database dump snapshots from the repository, cached for a few minutes.
   *
   * @return array{rows: list<array{date: string, class: string, name: string, snapshot: string, size: string}>, error: string|null}
   */
  public function dumps(): array {
    $cached = $this->cache->get(self::DUMPS_CACHE_ID);
    if ($cached !== FALSE && is_array($cached->data)) {
      return $cached->data;
    }
    try {
      $config = (new ConfigLoader(Application::detectProjectRoot(), Environment::fromGlobals()))->load();
      $restic = Restic::fromConfig($config, new ProcessRunner());
      $repo = $config->repositoryUrl(Config::STORE_DB);
      $restic->requireRepository($repo);
      $rows = [];
      foreach ($restic->snapshots($repo, ['host' => $config->resticHost()]) as $snapshot) {
        $rows[] = [
          'date' => $snapshot->time->format('Y-m-d H:i'),
          'class' => $snapshot->tags[0] ?? '',
          'name' => ltrim($snapshot->paths[0] ?? '', '/'),
          'snapshot' => $snapshot->shortId,
          'size' => DbListCommand::bytes($snapshot->totalBytesProcessed),
        ];
      }
      $result = ['rows' => $rows, 'error' => NULL];
    }
    catch (\Throwable $e) {
      $result = ['rows' => [], 'error' => $e->getMessage()];
    }
    $this->cache->set(self::DUMPS_CACHE_ID, $result, $this->time->getRequestTime() + self::DUMPS_CACHE_TTL);
    return $result;
  }

  /**
   * Seconds since the run finished, when it was a success; NULL otherwise.
   */
  private function successAge(?array $run): ?int {
    if ($run === NULL || ($run['outcome'] ?? NULL) !== 'success' || !is_string($run['finished'] ?? NULL)) {
      return NULL;
    }
    $finished = strtotime($run['finished']);
    return $finished === FALSE ? NULL : max(0, $this->time->getRequestTime() - $finished);
  }

  private function describe(?int $age): string {
    return $age === NULL ? 'no successful run' : $this->dateFormatter->formatInterval($age, 1) . ' ago';
  }

}
