<?php

declare(strict_types=1);

namespace Drupal\offsite_backup\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\offsite_backup\BackupStatus;

/**
 * Hook implementations for Drupal 11.2 and later (hook_runtime_requirements).
 *
 * Older cores never discover this class; they use the legacy hook in
 * offsite_backup.install instead.
 */
final class OffsiteBackupHooks {

  public function __construct(private readonly BackupStatus $status) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $entry = $this->status->requirement();
    $entry['severity'] = match ($entry['level']) {
      'ok' => RequirementSeverity::OK,
      'error' => RequirementSeverity::Error,
      default => RequirementSeverity::Warning,
    };
    unset($entry['level']);
    return ['offsite_backup' => $entry];
  }

}
