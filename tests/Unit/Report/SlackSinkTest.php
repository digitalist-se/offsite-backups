<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Report;

use Digitalist\OffsiteBackup\Report\RunReport;
use Digitalist\OffsiteBackup\Report\SlackSink;
use Digitalist\OffsiteBackup\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

final class SlackSinkTest extends TestCase
{
    private function failedReport(): RunReport
    {
        $report = new RunReport('db:backup', 'site', 'main');
        foreach (range(1, 25) as $i) {
            $report->addMessage('notice', "line $i");
        }
        $report->addMessage('error', 'Dump failed');
        $report->fail('Dump failed (exit 1)');
        return $report;
    }

    public function testPayloadShape(): void
    {
        $payload = SlackSink::payload($this->failedReport(), '#alerts');
        self::assertSame('#alerts', $payload['channel']);
        self::assertStringStartsWith('*:x: Offsite backup failed: site/main db:backup*', $payload['text']);
        self::assertStringContainsString('Dump failed (exit 1)', $payload['text']);
        self::assertStringContainsString('line 25', $payload['text']);
        self::assertStringNotContainsString("line 5\n", $payload['text'], 'only the last 20 lines');
    }

    public function testPayloadWithoutChannelLeavesItToTheWebhook(): void
    {
        $payload = SlackSink::payload($this->failedReport(), null);
        self::assertArrayNotHasKey('channel', $payload);
        self::assertStringContainsString('site/main db:backup', $payload['text']);
    }

    public function testDisallowedHostIsNotCalled(): void
    {
        $output = new BufferedOutput();
        $sink = new SlackSink('https://evil.example.com/hook', '#alerts', $output);
        $sink->onFailure($this->failedReport());
        self::assertStringContainsString('not in the allowlist', $output->fetch());
    }

    public function testNoWebhookMeansNoOp(): void
    {
        $output = new BufferedOutput();
        (new SlackSink(null, '#alerts', $output))->onFailure($this->failedReport());
        self::assertSame('', $output->fetch());
    }

    public function testPostsToTheWebhook(): void
    {
        $out = TempDir::create() . '/slack.json';
        $port = random_int(20000, 40000);
        $server = new Process(['php', '-S', "127.0.0.1:$port", __DIR__ . '/../../Support/slack-receiver.php'], null, ['SLACK_RECEIVER_OUT' => $out]);
        $server->start();
        try {
            usleep(500000);
            $output = new BufferedOutput();
            $sink = new SlackSink("http://127.0.0.1:$port/services/x", '#alerts', $output, ['127.0.0.1']);
            $sink->onFailure($this->failedReport());
            self::assertSame('', $output->fetch());
            $sent = json_decode((string) file_get_contents($out), true);
            self::assertSame('#alerts', $sent['channel']);
            self::assertStringContainsString('site/main db:backup', $sent['text']);
        } finally {
            $server->stop();
        }
    }
}
