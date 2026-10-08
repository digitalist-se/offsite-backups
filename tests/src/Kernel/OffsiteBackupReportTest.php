<?php

declare(strict_types=1);

namespace Drupal\Tests\offsite_backup\Kernel;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Restic\Snapshot;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\offsite_backup\BackupStatus;
use Drupal\offsite_backup\Controller\ReportController;
use Drupal\offsite_backup\RepositoryInsight;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Report page and status-report entry, driven by the State keys the CLI writes.
 *
 * @group offsite_backup
 */
#[RunTestsInSeparateProcesses]
class OffsiteBackupReportTest extends KernelTestBase {

  use UserCreationTrait;

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
    $this->installEntitySchema('user');
    // The entries are judged as on production unless a test says otherwise.
    putenv('PLATFORM_ENVIRONMENT_TYPE=production');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('PLATFORM_APPLICATION');
    putenv('PLATFORM_ENVIRONMENT_TYPE');
    parent::tearDown();
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
   * Exposes an app definition the way Upsun does, with the four backup crons.
   */
  private function seedPlatformApplication(): void {
    $crons = [];
    foreach (['db:backup' => '0 1 * * *', 'files:backup' => '0 2 * * *', 'prune' => '0 3 * * 0', 'check' => '0 5 * * 0'] as $command => $spec) {
      $crons[str_replace(':', '_', $command)] = ['spec' => $spec, 'commands' => ['start' => "cd /app && vendor/bin/offsite-backup $command"]];
    }
    $crons['drupal'] = ['spec' => '*/19 * * * *', 'commands' => ['start' => 'vendor/bin/drush cron']];
    putenv('PLATFORM_APPLICATION=' . base64_encode((string) json_encode(['name' => 'site', 'crons' => $crons])));
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

  private function backupStatus(): BackupStatus {
    return \Drupal::service('offsite_backup.status');
  }

  private function renderPage(): string {
    $build = ReportController::create($this->container)->page();
    self::assertSame(0, $build['#cache']['max-age']);
    return (string) $this->render($build);
  }

  public function testReportNeedsTheDedicatedPermission(): void {
    // uid 1 bypasses access checks; take it so the accounts below are ordinary.
    $this->createUser([], NULL, FALSE, ['uid' => 1]);
    $accessManager = $this->container->get('access_manager');
    self::assertFalse($accessManager->checkNamedRoute('offsite_backup.report', [], $this->createUser(['access site reports', 'administer site configuration'])));
    self::assertTrue($accessManager->checkNamedRoute('offsite_backup.report', [], $this->createUser(['view offsite backups'])));
  }

  public function testStatusEntryLinksToTheReportOnlyWithAccess(): void {
    $this->setUpCurrentUser([], ['administer site configuration']);
    $description = (string) $this->runtimeRequirement()['description'];
    self::assertStringNotContainsString('/admin/reports/offsite-backups', $description);
    self::assertStringContainsString('View offsite backups', $description);
  }

  public function testNoRunRecordedIsAWarning(): void {
    $this->setUpCurrentUser([], ['view offsite backups']);
    self::assertSame('unknown', $this->backupStatus()->freshness()['level']);
    $requirement = $this->runtimeRequirement();
    self::assertSame(RequirementSeverity::Warning, $requirement['severity']);
    self::assertStringContainsString('No offsite backup run recorded', (string) $requirement['value']);
    self::assertStringContainsString('/admin/reports/offsite-backups', (string) $requirement['description']);
  }

  public function testFreshSuccessfulRunsAreOk(): void {
    $this->seedRun('db_backup', 'success', 3600, ['name' => '2026-09-30-site-main.sql', 'snapshot' => 'abcd1234']);
    $this->seedRun('files_backup', 'success', 3000, ['snapshot' => 'ef567890']);
    $freshness = $this->backupStatus()->freshness();
    self::assertSame('ok', $freshness['level']);
    self::assertEqualsWithDelta(3600, $freshness['db_age'], 5);
    self::assertStringContainsString('Database', $freshness['summary']);
    self::assertStringContainsString('files', $freshness['summary']);
    self::assertSame(RequirementSeverity::OK, $this->runtimeRequirement()['severity']);
  }

  public function testStaleRunsWarnThenError(): void {
    $max = $this->backupStatus()->maxAgeSeconds();
    self::assertGreaterThan(0, $max);
    $this->seedRun('db_backup', 'success', $max + 3600);
    $this->seedRun('files_backup', 'success', 600);
    self::assertSame('warning', $this->backupStatus()->freshness()['level']);
    self::assertSame(RequirementSeverity::Warning, $this->runtimeRequirement()['severity']);

    $this->seedRun('db_backup', 'success', 2 * $max + 3600);
    self::assertSame('error', $this->backupStatus()->freshness()['level']);
    self::assertSame(RequirementSeverity::Error, $this->runtimeRequirement()['severity']);

    // A failed run does not count as fresh: with no successful db_backup on
    // record and a fresh files backup, the state is a warning.
    $this->seedRun('db_backup', 'failure', 60, [], 'Dump failed');
    $freshness = $this->backupStatus()->freshness();
    self::assertNull($freshness['db_age']);
    self::assertSame('warning', $freshness['level']);
    \Drupal::state()->delete('offsite_backup.run.files_backup');
    self::assertSame('unknown', $this->backupStatus()->freshness()['level'], 'nothing successful at all');
  }

  public function testJobEntriesCarrySeverityDetailsAndNextRun(): void {
    $max = $this->backupStatus()->maxAgeSeconds();
    $this->seedRun('db_backup', 'success', 3600, ['name' => '2026-09-30-site-main.sql', 'snapshot' => 'abcd1234', 'bytes' => 12345]);
    $this->seedRun('files_backup', 'failure', 600, ['snapshot' => NULL], 'restic exploded');
    $this->seedRun('check', 'success', 10 * 86400, ['stores' => ['db', 'files'], 'subset' => '5%']);
    $this->seedPlatformApplication();

    $entries = $this->backupStatus()->entries();
    self::assertSame(['db_backup', 'files_backup', 'prune', 'check'], array_keys($entries));
    self::assertSame(RequirementSeverity::OK, $entries['db_backup']['severity']);
    self::assertSame(RequirementSeverity::Error, $entries['files_backup']['severity'], 'last run failed');
    self::assertSame(RequirementSeverity::Warning, $entries['prune']['severity'], 'never run');
    self::assertSame(RequirementSeverity::OK, $entries['check']['severity'], 'weekly jobs do not go stale');

    self::assertStringContainsString('Succeeded 1 hour ago in 30 s', (string) $entries['db_backup']['value']);
    self::assertStringContainsString('2026-09-30-site-main.sql', (string) $this->render($entries['db_backup']['description']));
    self::assertStringContainsString('Next run', (string) $this->render($entries['db_backup']['description']));
    self::assertStringContainsString('Failed 10 min ago', (string) $entries['files_backup']['value']);
    self::assertStringContainsString('restic exploded', (string) $this->render($entries['files_backup']['description']));
    self::assertStringContainsString('Never run', (string) $entries['prune']['value']);

    $this->seedRun('db_backup', 'success', $max + 3600);
    self::assertSame(RequirementSeverity::Warning, $this->backupStatus()->entries()['db_backup']['severity'], 'older than status_max_age');
    $this->seedRun('db_backup', 'success', 2 * $max + 3600);
    self::assertSame(RequirementSeverity::Error, $this->backupStatus()->entries()['db_backup']['severity'], 'older than twice status_max_age');
  }

  public function testScheduleComesFromThePlatformWhenExposed(): void {
    self::assertSame([], $this->backupStatus()->schedule());
    $this->seedPlatformApplication();
    $schedule = $this->backupStatus()->schedule();
    self::assertCount(4, $schedule);
    self::assertSame('db_backup', $schedule[0]->job);
    self::assertSame('0 1 * * *', $schedule[0]->spec);
    self::assertNotNull($schedule[0]->next);
  }

  public function testConfigurationListsResolvedValuesWithoutSecrets(): void {
    $configuration = $this->backupStatus()->configuration();
    $keys = array_column($configuration['rows'], 'key');
    self::assertContains('project', $keys);
    self::assertContains('s3.host', $keys);
    self::assertContains('retention.daily', $keys);
    foreach (['aws_access_key_id', 'aws_secret_access_key', 'restic_password', 'slack.webhook_url'] as $secret) {
      self::assertNotContains($secret, $keys);
    }
    foreach ($configuration['rows'] as $row) {
      self::assertContains($row['source'], ['default', 'file', 'env']);
      self::assertIsString($row['value']);
    }
  }

  public function testReportPageRendersEverySectionAndSaysWhenRepositoriesAreUnavailable(): void {
    $this->seedRun('db_backup', 'success', 3600, ['name' => '2026-09-30-site-main.sql', 'snapshot' => 'abcd1234', 'bytes' => 12345]);
    $this->seedRun('prune', 'failure', 7200, ['dry_run' => FALSE], 'Retention failed for db: boom');
    $html = $this->renderPage();

    self::assertStringContainsString('system-status-report', $html, 'core status report markup');
    self::assertStringContainsString('Errors found', $html);
    self::assertStringContainsString('Database backup', $html);
    self::assertStringContainsString('abcd1234', $html);
    self::assertStringContainsString('Retention failed for db: boom', $html);
    self::assertStringContainsString('Files backup', $html, 'jobs without a run are still listed');
    self::assertStringContainsString('Repository check', $html);
    self::assertStringContainsString('Repositories', $html);
    self::assertStringContainsString('Available database dumps', $html);
    self::assertStringContainsString('Available files snapshots', $html);
    self::assertStringContainsString('Configuration', $html);
    self::assertStringContainsString('s3.host', $html);
    self::assertStringNotContainsString('Next run', $html, 'no platform schedule exposed');
    // No secrets in the test environment: every repository section degrades to a message.
    self::assertStringContainsString('Repository overview unavailable', $html);
    self::assertStringContainsString('Dump listing unavailable', $html);
    self::assertStringContainsString('Files snapshot listing unavailable', $html);
  }

  public function testReportPageShowsTheScheduleWhenExposed(): void {
    $this->seedPlatformApplication();
    $html = $this->renderPage();
    self::assertStringContainsString('Schedule', $html);
    self::assertStringContainsString('0 1 * * *', $html);
    self::assertStringContainsString('Next run', $html);
    self::assertStringNotContainsString('drush cron', $html, 'only the backup crons');
  }

  public function testOlderOrPartialStatePayloadsRenderWithDashes(): void {
    \Drupal::state()->set('offsite_backup.run.files_backup', ['outcome' => 'success']);
    \Drupal::state()->set('offsite_backup.run.check', 'not an array');
    $html = $this->renderPage();
    self::assertStringContainsString('Files backup', $html);
    self::assertStringContainsString('Repository check', $html);
    self::assertSame('unknown', $this->backupStatus()->freshness()['level'], 'a success without a finished time is not fresh');
  }

  public function testSnapshotRowsNameDumpsByFileAndFilesByEveryPath(): void {
    $snapshot = new Snapshot('abcdef0123456789', 'abcdef01', new \DateTimeImmutable('2026-09-30 01:00:00'), 'site-main', ['/app/web/sites/default/files', '/app/private'], ['daily'], 2048);
    $row = RepositoryInsight::rowFor($snapshot, Config::STORE_FILES);
    self::assertSame('/app/web/sites/default/files, /app/private', $row['name']);
    self::assertSame('2026-09-30 01:00', $row['date']);
    self::assertSame('daily', $row['class']);
    self::assertSame('abcdef01', $row['snapshot']);
    self::assertSame('2.0 KiB', $row['size']);

    $dump = new Snapshot('fedcba9876543210', 'fedcba98', new \DateTimeImmutable('2026-09-30 01:00:00'), 'site-main', ['/2026-09-30-site-main.sql'], ['monthly'], NULL);
    self::assertSame('2026-09-30-site-main.sql', RepositoryInsight::rowFor($dump, Config::STORE_DB)['name']);
    $files = \Drupal::service('offsite_backup.repositories')->listing(Config::STORE_FILES);
    self::assertSame([], $files['rows']);
    self::assertIsString($files['error']);
  }

  public function testCopiesOfProductionGetInformationalEntries(): void {
    putenv('PLATFORM_ENVIRONMENT_TYPE=development');
    $max = $this->backupStatus()->maxAgeSeconds();
    $this->seedRun('db_backup', 'success', 2 * $max + 3600, ['name' => '2026-09-30-site-main.sql', 'snapshot' => 'abcd1234']);
    $this->seedRun('files_backup', 'failure', 600, [], 'restic exploded');
    $this->seedPlatformApplication();

    $placement = $this->backupStatus()->placement();
    self::assertFalse($placement['runs']);
    self::assertSame('development', $placement['type']);
    self::assertSame(['production'], $placement['enabled']);
    self::assertSame('error', $this->backupStatus()->freshness()['level'], 'the data itself is still judged');

    $requirement = $this->runtimeRequirement();
    self::assertSame(RequirementSeverity::Info, $requirement['severity']);
    self::assertStringContainsString('development', (string) $requirement['value']);
    self::assertStringContainsString('Database', (string) $requirement['value']);

    $entries = $this->backupStatus()->entries();
    foreach ($entries as $entry) {
      self::assertSame(RequirementSeverity::Info, $entry['severity']);
    }
    $description = (string) $this->render($entries['db_backup']['description']);
    self::assertStringContainsString('Not run here', $description);
    self::assertStringContainsString('skips on this environment type', $description);
    self::assertStringContainsString('restic exploded', (string) $this->render($entries['files_backup']['description']), 'production\'s error is still shown');

    putenv('PLATFORM_ENVIRONMENT_TYPE=production');
    self::assertSame(RequirementSeverity::Error, $this->runtimeRequirement()['severity']);
  }

}
