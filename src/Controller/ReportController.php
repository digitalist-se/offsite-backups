<?php

declare(strict_types=1);

namespace Drupal\offsite_backup\Controller;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Platform\ScheduledJob;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\offsite_backup\BackupStatus;
use Drupal\offsite_backup\RepositoryInsight;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * /admin/reports/offsite-backups.
 */
final class ReportController extends ControllerBase {

  public function __construct(
    private readonly BackupStatus $status,
    private readonly RepositoryInsight $repositories,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('offsite_backup.status'), $container->get('offsite_backup.repositories'), $container->get('date.formatter'));
  }

  /**
   * The report page.
   */
  public function page(): array {
    $build['#attached']['library'][] = 'system/status.report';
    $build['jobs'] = [
      '#type' => 'status_report',
      '#requirements' => $this->status->entries(),
    ];

    $build['repositories_title'] = $this->heading($this->t('Repositories'));
    $overview = $this->repositories->overview();
    if ($overview['error'] !== NULL) {
      $build['repositories'] = $this->note($this->t('Repository overview unavailable: @reason', ['@reason' => $overview['error']]));
    }
    else {
      $build['repositories'] = [
        '#type' => 'table',
        '#header' => [$this->t('Repository'), $this->t('Snapshots'), $this->t('Storage used'), $this->t('Oldest'), $this->t('Newest'), $this->t('Retention coverage'), $this->t('Note')],
        '#rows' => array_map(static fn (array $r): array => [$r['label'] . ' (' . $r['repository'] . ')', $r['snapshots'], $r['size'], $r['oldest'], $r['newest'], $r['coverage'], $r['note']], $overview['rows']),
      ];
    }

    $build['dumps_title'] = $this->heading($this->t('Available database dumps'));
    $dumps = $this->repositories->listing(Config::STORE_DB);
    $build['dumps'] = $this->listing(
      $dumps,
      $this->t('Name'),
      $this->t('Dump listing unavailable: @reason', ['@reason' => (string) $dumps['error']]),
      $this->t('No dumps in the repository yet.'),
    );
    $build['files_title'] = $this->heading($this->t('Available files snapshots'));
    $files = $this->repositories->listing(Config::STORE_FILES);
    $build['files'] = $this->listing(
      $files,
      $this->t('Paths'),
      $this->t('Files snapshot listing unavailable: @reason', ['@reason' => (string) $files['error']]),
      $this->t('No files snapshots in the repository yet.'),
    );

    $schedule = $this->status->schedule();
    if ($schedule !== []) {
      $build['schedule_title'] = $this->heading($this->t('Schedule'));
      $build['schedule'] = [
        '#type' => 'table',
        '#header' => [$this->t('Job'), $this->t('Cron'), $this->t('Next run'), $this->t('Command')],
        '#rows' => array_map(fn (ScheduledJob $job): array => [
          BackupStatus::JOBS[$job->job] ?? $job->job,
          $job->spec,
          $job->next === NULL ? '-' : $this->dateFormatter->format($job->next->getTimestamp(), 'custom', 'D Y-m-d H:i T'),
          $job->command,
        ], $schedule),
      ];
    }

    $configuration = $this->status->configuration();
    $build['configuration'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuration'),
      '#open' => FALSE,
    ];
    if ($configuration['error'] !== NULL) {
      $build['configuration']['note'] = $this->note($this->t('Configuration unavailable: @reason', ['@reason' => $configuration['error']]));
    }
    else {
      $build['configuration']['table'] = [
        '#type' => 'table',
        '#header' => [$this->t('Setting'), $this->t('Value'), $this->t('Source')],
        '#rows' => array_map(static fn (array $r): array => [$r['key'], $r['value'], $r['source']], $configuration['rows']),
      ];
    }
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  private function heading(TranslatableMarkup $title): array {
    return ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $title];
  }

  private function note(TranslatableMarkup $text): array {
    return ['#type' => 'html_tag', '#tag' => 'p', '#value' => $text];
  }

  /**
   * A snapshot table, or the reason the repository could not be listed.
   *
   * @param array{rows: list<array{date: string, class: string, name: string, snapshot: string, size: string}>, error: string|null} $listing
   */
  private function listing(array $listing, TranslatableMarkup $nameHeader, TranslatableMarkup $unavailable, TranslatableMarkup $empty): array {
    if ($listing['error'] !== NULL) {
      return $this->note($unavailable);
    }
    return [
      '#type' => 'table',
      '#header' => [$this->t('Date'), $this->t('Class'), $nameHeader, $this->t('Snapshot'), $this->t('Size')],
      '#rows' => array_map(static fn (array $r): array => [$r['date'], $r['class'], $r['name'], $r['snapshot'], $r['size']], $listing['rows']),
      '#empty' => $empty,
    ];
  }

}
