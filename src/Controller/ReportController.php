<?php

declare(strict_types=1);

namespace Drupal\offsite_backup\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\offsite_backup\BackupStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * /admin/reports/offsite-backups.
 */
final class ReportController extends ControllerBase {

  public function __construct(private readonly BackupStatus $status) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('offsite_backup.status'));
  }

  /**
   * The report page.
   */
  public function page(): array {
    $freshness = $this->status->freshness();
    $build['freshness'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('@summary Status: @level.', ['@summary' => $freshness['summary'], '@level' => $freshness['level']]),
    ];

    $rows = [];
    foreach ($this->status->lastRuns() as $job => $run) {
      $rows[] = [
        BackupStatus::JOBS[$job],
        $run['finished'] ?? '-',
        $run['outcome'] ?? '-',
        isset($run['duration_seconds']) ? $run['duration_seconds'] . ' s' : '-',
        $this->describeDetails($run),
      ];
    }
    $build['runs'] = [
      '#type' => 'table',
      '#caption' => $this->t('Last run per job'),
      '#header' => [$this->t('Job'), $this->t('Finished'), $this->t('Outcome'), $this->t('Duration'), $this->t('Details')],
      '#rows' => $rows,
    ];

    $build['dumps_title'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Available database dumps')];
    $dumps = $this->status->dumps();
    if ($dumps['error'] !== NULL) {
      $build['dumps'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Dump listing unavailable: @reason', ['@reason' => $dumps['error']]),
      ];
    }
    else {
      $build['dumps'] = [
        '#type' => 'table',
        '#header' => [$this->t('Date'), $this->t('Class'), $this->t('Name'), $this->t('Snapshot'), $this->t('Size')],
        '#rows' => array_map(static fn (array $r): array => [$r['date'], $r['class'], $r['name'], $r['snapshot'], $r['size']], $dumps['rows']),
        '#empty' => $this->t('No dumps in the repository yet.'),
      ];
    }
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * One line of details or the error, whatever the payload's shape.
   */
  private function describeDetails(?array $run): string {
    if ($run === NULL) {
      return '-';
    }
    if (is_string($run['error'] ?? NULL) && $run['error'] !== '') {
      return $run['error'];
    }
    $details = is_array($run['details'] ?? NULL) ? $run['details'] : [];
    $parts = [];
    foreach ($details as $key => $value) {
      $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value));
    }
    return $parts === [] ? '-' : implode(', ', $parts);
  }

}
