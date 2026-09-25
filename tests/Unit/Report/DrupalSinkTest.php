<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Report;

use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Digitalist\OffsiteBackup\Report\DrupalSink;
use Digitalist\OffsiteBackup\Report\RunReport;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use Digitalist\OffsiteBackup\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class DrupalSinkTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = TempDir::create() . '/drush.log';
        TestEnv::set('FAKE_DRUSH_OUT', $this->out);
        TestEnv::unset('FAKE_DRUSH_FAIL');
    }

    private function sink(BufferedOutput $output): DrupalSink
    {
        return new DrupalSink(new ProcessRunner(), __DIR__ . '/../../Support/fake-drush.php', '/tmp/web', 'offsite-backup', 'offsite_backup.run.db_backup', $output);
    }

    /** @return list<array<string,mixed>> */
    private function records(): array
    {
        return array_values(array_map(static fn (string $l): array => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($this->out)))));
    }

    public function testFlushesBatchesAtStartFailureAndEndWithState(): void
    {
        $output = new BufferedOutput();
        $report = new RunReport('db:backup', 'site', 'main');
        $sink = $this->sink($output);

        $report->addMessage('notice', 'Backup started');
        $sink->onStart($report);
        $report->addMessage('notice', 'progress');
        $report->addMessage('error', 'boom');
        $report->fail('boom');
        $sink->onFailure($report);
        $sink->onEnd($report);

        $records = $this->records();
        self::assertCount(3, $records);
        self::assertSame(['--root=/tmp/web', 'php:eval'], array_slice($records[0]['argv'], 0, 2));
        self::assertStringContainsString('\Drupal::logger', $records[0]['argv'][2]);
        self::assertSame([['channel' => 'offsite-backup', 'level' => 'notice', 'message' => 'Backup started']], $records[0]['stdin']['log']);
        self::assertArrayNotHasKey('state', $records[0]['stdin']);
        self::assertSame(['progress', 'boom'], array_column($records[1]['stdin']['log'], 'message'));
        self::assertSame([], $records[2]['stdin']['log'], 'nothing new since the failure flush');
        self::assertSame('failure', $records[2]['stdin']['state']['offsite_backup.run.db_backup']['outcome']);
        self::assertSame('', $output->fetch());
    }

    public function testDrushFailureIsAWarningNotAnException(): void
    {
        TestEnv::set('FAKE_DRUSH_FAIL', '1');
        $output = new BufferedOutput();
        $report = new RunReport('db:backup', 'site', 'main');
        $report->addMessage('notice', 'x');
        $this->sink($output)->onStart($report);
        self::assertStringContainsString('Drupal log hand-over failed', $output->fetch());
    }
}
