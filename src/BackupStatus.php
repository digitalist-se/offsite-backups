<?php

declare(strict_types=1);

namespace Drupal\offsite_backup;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Backup\Duration;
use Digitalist\OffsiteBackup\Config\ConfigLoader;
use Digitalist\OffsiteBackup\Config\Settings;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Platform\ScheduledJob;
use Digitalist\OffsiteBackup\Platform\UpsunSchedule;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Reads what the offsite-backup CLI left in State, plus the schedule and
 * configuration it runs with.
 */
final class BackupStatus {

  public const JOBS = [
    'db_backup' => 'Database backup',
    'files_backup' => 'Files backup',
    'prune' => 'Retention',
    'check' => 'Repository check',
  ];

  private const BACKUP_JOBS = ['db_backup', 'files_backup'];
  private const INFO = 'info';
  private const DEFAULT_MAX_AGE = 93600;

  public function __construct(
    private readonly StateInterface $state,
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
   * Whether the gated commands run on this environment: its platform type is
   * one of the configured environment_types. Copies of production (preview
   * environments, stage, local sites) carry production's State but never
   * refresh it, so their entries must not look like failures.
   *
   * @return array{runs: bool, type: string|null, enabled: list<string>}
   */
  public function placement(): array {
    $env = Environment::fromGlobals();
    $type = $env->get('PLATFORM_ENVIRONMENT_TYPE');
    try {
      $enabled = (new ConfigLoader(Application::detectProjectRoot(), $env))->resolve()->values['environment_types'];
    }
    catch (\Throwable) {
      $enabled = Settings::all()['environment_types']['default'];
    }
    $enabled = array_values(array_map('strval', (array) $enabled));
    return ['runs' => $type !== NULL && in_array($type, $enabled, TRUE), 'type' => $type, 'enabled' => $enabled];
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
    $report = Url::fromRoute('offsite_backup.report');
    $description = $report->access()
      ? new TranslatableMarkup('See the <a href=":url">offsite backups report</a>.', [':url' => $report->toString()])
      : new TranslatableMarkup('The offsite backups report needs the "View offsite backups" permission.');
    $placement = $this->placement();
    if (!$placement['runs']) {
      return [
        'title' => new TranslatableMarkup('Offsite backups'),
        'value' => new TranslatableMarkup('Not run on this environment (type @type; runs on: @enabled). Last recorded production run: @summary', [
          '@type' => $placement['type'] ?? 'unset',
          '@enabled' => implode(', ', $placement['enabled']),
          '@summary' => $freshness['level'] === 'unknown' ? 'none' : $freshness['summary'],
        ]),
        'description' => $description,
        'level' => self::INFO,
      ];
    }
    return [
      'title' => new TranslatableMarkup('Offsite backups'),
      'value' => $freshness['level'] === 'unknown' ? new TranslatableMarkup('No offsite backup run recorded yet.') : $freshness['summary'],
      'description' => $description,
      'level' => $freshness['level'],
    ];
  }

  /**
   * One status-report entry per job, for the status_report render element.
   *
   * A failed last run is an error and a job never run a warning. A backup
   * older than status_max_age is a warning, older than twice that an error;
   * retention and check are weekly and do not go stale. Where the commands do
   * not run (see placement()) every entry is informational: the State was
   * copied from production and describes production, not this environment.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, value: string, description: array<string, mixed>, severity: \Drupal\Core\Extension\Requirement\RequirementSeverity}>
   */
  public function entries(): array {
    $runs = $this->lastRuns();
    $max = $this->maxAgeSeconds();
    $next = [];
    foreach ($this->schedule() as $scheduled) {
      $next[$scheduled->job] = $scheduled->next;
    }
    $placement = $this->placement();
    $entries = [];
    foreach (self::JOBS as $job => $label) {
      $run = $runs[$job];
      $age = $this->finishedAge($run);
      $duration = isset($run['duration_seconds']) ? $run['duration_seconds'] . ' s' : '?';
      if ($run === NULL || $age === NULL) {
        $severity = RequirementSeverity::Warning;
        $value = $run === NULL ? 'Never run' : 'Recorded without a finish time';
      }
      elseif (($run['outcome'] ?? NULL) !== 'success') {
        $severity = RequirementSeverity::Error;
        $value = sprintf('Failed %s ago in %s', $this->dateFormatter->formatInterval($age, 1), $duration);
      }
      else {
        $stale = in_array($job, self::BACKUP_JOBS, TRUE);
        $severity = $stale && $age > 2 * $max ? RequirementSeverity::Error : ($stale && $age > $max ? RequirementSeverity::Warning : RequirementSeverity::OK);
        $value = sprintf('Succeeded %s ago in %s', $this->dateFormatter->formatInterval($age, 1), $duration);
      }
      $lines = [];
      if (!$placement['runs']) {
        $severity = RequirementSeverity::Info;
        $lines[] = sprintf('Not run here (environment type %s; runs on %s). This is what production recorded.', $placement['type'] ?? 'unset', implode(', ', $placement['enabled']));
      }
      if (is_string($run['error'] ?? NULL) && $run['error'] !== '') {
        $lines[] = $run['error'];
      }
      $details = self::describeDetails($run);
      if ($details !== '') {
        $lines[] = $details;
      }
      if (isset($run['finished']) && is_string($run['finished'])) {
        $lines[] = 'Finished ' . $run['finished'];
      }
      if (isset($next[$job])) {
        $lines[] = 'Next run ' . $this->dateFormatter->format($next[$job]->getTimestamp(), 'custom', 'D Y-m-d H:i T') . ($placement['runs'] ? '' : ' (skips on this environment type)');
      }
      $entries[$job] = [
        'title' => new TranslatableMarkup($label),
        'value' => $value,
        'description' => [
          '#type' => 'inline_template',
          '#template' => '{% for line in lines %}{{ line }}{% if not loop.last %}<br>{% endif %}{% endfor %}',
          '#context' => ['lines' => $lines],
        ],
        'severity' => $severity,
      ];
    }
    return $entries;
  }

  /**
   * The backup crons the platform exposes, empty when it exposes none.
   *
   * @return list<\Digitalist\OffsiteBackup\Platform\ScheduledJob>
   */
  public function schedule(): array {
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone(new \DateTimeZone('UTC'));
    return UpsunSchedule::fromEnvironment(Environment::fromGlobals(), $now);
  }

  /**
   * The resolved non-secret settings with their source, as config:check shows them.
   *
   * @return array{rows: list<array{key: string, value: string, source: string}>, error: string|null}
   */
  public function configuration(): array {
    try {
      $resolved = (new ConfigLoader(Application::detectProjectRoot(), Environment::fromGlobals()))->resolve();
    }
    catch (\Throwable $e) {
      return ['rows' => [], 'error' => $e->getMessage()];
    }
    $definitions = Settings::all();
    $rows = [];
    foreach ($resolved->values as $key => $value) {
      if (($definitions[$key]['type'] ?? '') === 'secret') {
        continue;
      }
      $rows[] = ['key' => (string) $key, 'value' => self::formatValue($value), 'source' => $resolved->sources[$key] ?? 'default'];
    }
    return ['rows' => $rows, 'error' => NULL];
  }

  /**
   * The run's details as "key=value" pairs, or the empty string.
   */
  public static function describeDetails(?array $run): string {
    $details = is_array($run['details'] ?? NULL) ? $run['details'] : [];
    $parts = [];
    foreach ($details as $key => $value) {
      if ($value === NULL) {
        continue;
      }
      if ($key === 'inventory' && is_array($value)) {
        $counts = is_array($value['counts'] ?? NULL) ? $value['counts'] : [];
        $summary = implode(' ', array_map(static fn ($class, $n): string => $class . ':' . (is_scalar($n) ? (string) $n : '?'), array_keys($counts), $counts));
        $oldest = is_string($value['oldest'] ?? NULL) ? ' oldest:' . substr($value['oldest'], 0, 10) : '';
        $alert = is_string($value['alert'] ?? NULL) && $value['alert'] !== '' ? ' ALERT:' . $value['alert'] : '';
        $parts[] = 'inventory=' . $summary . $oldest . $alert;
        continue;
      }
      $parts[] = $key . '=' . (is_scalar($value) ? var_export($value, TRUE) : json_encode($value));
    }
    return implode(', ', $parts);
  }

  private static function formatValue(mixed $value): string {
    return match (TRUE) {
      is_array($value) => implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v), $value)),
      is_bool($value) => $value ? 'true' : 'false',
      $value === NULL => '',
      default => (string) $value,
    };
  }

  /**
   * Seconds since the run finished, when it was a success; NULL otherwise.
   */
  private function successAge(?array $run): ?int {
    return ($run['outcome'] ?? NULL) === 'success' ? $this->finishedAge($run) : NULL;
  }

  /**
   * Seconds since the run finished, whatever its outcome; NULL without a time.
   */
  private function finishedAge(?array $run): ?int {
    if ($run === NULL || !is_string($run['finished'] ?? NULL)) {
      return NULL;
    }
    $finished = strtotime($run['finished']);
    return $finished === FALSE ? NULL : max(0, $this->time->getRequestTime() - $finished);
  }

  private function describe(?int $age): string {
    return $age === NULL ? 'no successful run' : $this->dateFormatter->formatInterval($age, 1) . ' ago';
  }

}
