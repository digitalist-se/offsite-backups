<?php

declare(strict_types=1);

namespace Drupal\Tests\offsite_backup\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\offsite_backup\BackupStatus;
use Drupal\offsite_backup\Controller\ReportController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Report page and status-report entry, driven by the State keys the CLI writes.
 *
 * @group offsite_backup
 */
#[RunTestsInSeparateProcesses]
class OffsiteBackupReportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'offsite_backup'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
  }

  /**
   * Stores a run report the way the CLI does.
   */
  private function seedRun(string $job, string $outcome, int $finishedAgoSeconds, array $details = [], ?string $error = NULL): void {
    $finished = $this->container->get('datetime.time')->getRequestTime() - $finishedAgoSeconds;
    \Drupal::state()->set("offsite_backup.run.$job", [
      'command' => str_replace('_', ':', $job),
      'project' => 'site',
      'environment' => 'main',
      'started' => date(DATE_ATOM, $finished - 30),
      'finished' => date(DATE_ATOM, $finished),
      'duration_seconds' => 30,
      'outcome' => $outcome,
      'details' => $details,
      'error' => $error,
    ]);
  }

  /**
   * The status-report entry as the System module collects it.
   */
  private function runtimeRequirement(): array {
    $ours = $this->container->get('module_handler')->invoke('offsite_backup', 'runtime_requirements');
    self::assertIsArray($ours);
    self::assertArrayHasKey('offsite_backup', $ours);
    return $ours['offsite_backup'];
  }

  public function testNoRunRecordedIsAWarning(): void {
    $status = \Drupal::service('offsite_backup.status');
    self::assertInstanceOf(BackupStatus::class, $status);
    self::assertSame('unknown', $status->freshness()['level']);
    $requirement = $this->runtimeRequirement();
    self::assertSame(RequirementSeverity::Warning, $requirement['severity']);
    self::assertStringContainsString('No offsite backup run recorded', (string) $requirement['value']);
    self::assertStringContainsString('/admin/reports/offsite-backups', (string) $requirement['description']);
  }

  public function testFreshSuccessfulRunsAreOk(): void {
    $this->seedRun('db_backup', 'success', 3600, ['name' => '2026-09-30-site-main.sql', 'snapshot' => 'abcd1234']);
    $this->seedRun('files_backup', 'success', 3000, ['snapshot' => 'ef567890']);
    $freshness = \Drupal::service('offsite_backup.status')->freshness();
    self::assertSame('ok', $freshness['level']);
    self::assertEqualsWithDelta(3600, $freshness['db_age'], 5);
    self::assertStringContainsString('Database', $freshness['summary']);
    self::assertStringContainsString('files', $freshness['summary']);
    self::assertSame(RequirementSeverity::OK, $this->runtimeRequirement()['severity']);
  }

  public function testStaleRunsWarnThenError(): void {
    $max = \Drupal::service('offsite_backup.status')->maxAgeSeconds();
    self::assertGreaterThan(0, $max);
    $this->seedRun('db_backup', 'success', $max + 3600);
    $this->seedRun('files_backup', 'success', 600);
    self::assertSame('warning', \Drupal::service('offsite_backup.status')->freshness()['level']);
    self::assertSame(RequirementSeverity::Warning, $this->runtimeRequirement()['severity']);

    $this->seedRun('db_backup', 'success', 2 * $max + 3600);
    self::assertSame('error', \Drupal::service('offsite_backup.status')->freshness()['level']);
    self::assertSame(RequirementSeverity::Error, $this->runtimeRequirement()['severity']);

    // A failed run does not count as fresh: with no successful db_backup on
    // record and a fresh files backup, the state is a warning.
    $this->seedRun('db_backup', 'failure', 60, [], 'Dump failed');
    $freshness = \Drupal::service('offsite_backup.status')->freshness();
    self::assertNull($freshness['db_age']);
    self::assertSame('warning', $freshness['level']);
    \Drupal::state()->delete('offsite_backup.run.files_backup');
    self::assertSame('unknown', \Drupal::service('offsite_backup.status')->freshness()['level'], 'nothing successful at all');
  }

  public function testReportPageRendersRunsAndSaysWhenDumpsAreUnavailable(): void {
    $this->seedRun('db_backup', 'success', 3600, ['name' => '2026-09-30-site-main.sql', 'snapshot' => 'abcd1234', 'bytes' => 12345]);
    $this->seedRun('prune', 'failure', 7200, ['dry_run' => FALSE], 'Retention failed for db: boom');
    $build = ReportController::create($this->container)->page();
    $html = (string) $this->render($build);

    self::assertStringContainsString('Database backup', $html);
    self::assertStringContainsString('abcd1234', $html);
    self::assertStringContainsString('success', $html);
    self::assertStringContainsString('Retention failed for db: boom', $html);
    self::assertStringContainsString('Files backup', $html, 'jobs without a run are still listed');
    self::assertStringContainsString('Database 1 hour ago', $html);
    self::assertStringContainsString('Status: warning', $html, 'files never backed up is a warning, not an error');
    // No secrets in the test environment: the listing degrades to a message.
    self::assertStringContainsString('unavailable', $html);
    self::assertSame(0, $build['#cache']['max-age']);
  }

  public function testOlderOrPartialStatePayloadsRenderWithDashes(): void {
    \Drupal::state()->set('offsite_backup.run.files_backup', ['outcome' => 'success']);
    \Drupal::state()->set('offsite_backup.run.check', 'not an array');
    $build = ReportController::create($this->container)->page();
    $html = (string) $this->render($build);
    self::assertStringContainsString('Files backup', $html);
    self::assertStringContainsString('Repository check', $html);
    self::assertSame('unknown', \Drupal::service('offsite_backup.status')->freshness()['level'], 'a success without a finished time is not fresh');
  }

}
