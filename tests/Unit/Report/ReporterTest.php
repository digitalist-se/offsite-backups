<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Report;

use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Report\RunReport;
use Digitalist\OffsiteBackup\Report\Sink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ReporterTest extends TestCase
{
    public function testLogsToConsoleRecordsInReportAndDrivesSinks(): void
    {
        // A recording sink exercises the real Sink interface; it stands in for nothing external.
        $sink = new class implements Sink {
            /** @var list<string> */
            public array $calls = [];
            public function onStart(RunReport $r): void { $this->calls[] = 'start:' . $r->count(); }
            public function onFailure(RunReport $r): void { $this->calls[] = 'failure:' . $r->count(); }
            public function onEnd(RunReport $r): void { $this->calls[] = 'end:' . $r->outcome; }
        };
        $output = new BufferedOutput();
        $report = new RunReport('db:backup', 'p', 'e');
        $reporter = new Reporter($report, $output, [$sink]);

        $reporter->start();
        $reporter->notice('Backup started. file={file}', ['file' => 'x.sql']);
        $reporter->error('failed');
        $report->fail('failed');
        $reporter->failure();
        $reporter->finish();

        self::assertSame(['start:0', 'failure:2', 'end:failure'], $sink->calls);
        self::assertSame('Backup started. file=x.sql', $report->messages()[0]['message']);
        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] NOTICE  Backup started\. file=x\.sql$/m', $output->fetch());
    }

    public function testSinkExceptionsDoNotEscape(): void
    {
        $sink = new class implements Sink {
            public function onStart(RunReport $r): void { throw new \RuntimeException('sink down'); }
            public function onFailure(RunReport $r): void {}
            public function onEnd(RunReport $r): void {}
        };
        $output = new BufferedOutput();
        $reporter = new Reporter(new RunReport('c', 'p', 'e'), $output, [$sink]);
        $reporter->start();
        self::assertStringContainsString('sink down', $output->fetch());
    }
}
