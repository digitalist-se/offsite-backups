<?php

declare(strict_types=1);

namespace Drupal\offsite_backup\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\offsite_backup\BackupStatus;

/**
 * Hook implementations (hook_runtime_requirements needs Drupal 11.2 or later,
 * which is the module's minimum; the CLI itself has no Drupal requirement).
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
