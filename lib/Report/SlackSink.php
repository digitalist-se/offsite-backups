<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Report;

use Symfony\Component\Console\Output\OutputInterface;

/** Posts one message to a Slack incoming webhook when a run fails. Never throws. */
final class SlackSink implements Sink
{
    private const TAIL_LINES = 20;

    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly ?string $webhookUrl,
        private readonly string $channel,
        private readonly OutputInterface $output,
        private readonly array $allowedHosts = ['hooks.slack.com'],
    ) {}

    public function onStart(RunReport $report): void {}

    public function onEnd(RunReport $report): void {}

    public function onFailure(RunReport $report): void
    {
        if ($this->webhookUrl === null) {
            return;
        }
        $host = parse_url($this->webhookUrl, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), $this->allowedHosts, true)) {
            $this->output->writeln('<comment>Slack webhook host is not in the allowlist (' . implode(', ', $this->allowedHosts) . '); notification not sent.</comment>');
            return;
        }
        $body = (string) json_encode(self::payload($report, $this->channel), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
        $response = @file_get_contents($this->webhookUrl, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($response === false || $status < 200 || $status >= 300) {
            $this->output->writeln(sprintf('<comment>Slack notification to %s failed (HTTP %d).</comment>', $host, $status));
        }
    }

    /** @return array{channel: string, text: string} */
    public static function payload(RunReport $report, string $channel): array
    {
        $lines = array_map(static fn (array $m): string => sprintf('[%s] %s', $m['level'], $m['message']), $report->messages());
        $tail = implode("\n", array_slice($lines, -self::TAIL_LINES));
        $text = sprintf("*:x: Offsite backup failed: %s/%s %s*\n%s\n```%s```", $report->project, $report->environment, $report->command, $report->error ?? 'unknown error', $tail);
        return ['channel' => $channel, 'text' => $text];
    }
}
