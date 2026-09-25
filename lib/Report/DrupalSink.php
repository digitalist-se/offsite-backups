<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Report;

use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hands log lines and the run result to Drupal through one `drush php:eval`
 * per flush, JSON on stdin. Best effort: a failure prints a warning and never
 * changes the run outcome.
 */
final class DrupalSink implements Sink
{
    public const BRIDGE = '$in = json_decode(file_get_contents("php://stdin"), TRUE); foreach ($in["log"] ?? [] as $m) { \Drupal::logger($m["channel"])->log($m["level"], $m["message"]); } foreach ($in["state"] ?? [] as $k => $v) { \Drupal::state()->set($k, $v); }';

    private int $flushed = 0;

    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $drushBin,
        private readonly string $drupalRoot,
        private readonly string $channel,
        private readonly string $stateKey,
        private readonly OutputInterface $output,
    ) {}

    public function onStart(RunReport $report): void
    {
        $this->flush($report, false);
    }

    public function onFailure(RunReport $report): void
    {
        $this->flush($report, false);
    }

    public function onEnd(RunReport $report): void
    {
        $this->flush($report, true);
    }

    private function flush(RunReport $report, bool $withState): void
    {
        $messages = $report->messagesSince($this->flushed);
        $this->flushed = $report->count();
        if ($messages === [] && !$withState) {
            return;
        }
        $payload = ['log' => array_map(fn (array $m): array => ['channel' => $this->channel, 'level' => $m['level'], 'message' => $m['message']], $messages)];
        if ($withState) {
            $payload['state'] = [$this->stateKey => $report->toArray()];
        }
        $result = $this->runner->run([$this->drushBin, '--root=' . $this->drupalRoot, 'php:eval', self::BRIDGE], [], (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 180);
        if (!$result->ok()) {
            $this->output->writeln(sprintf('<comment>Drupal log hand-over failed (exit %d): %s</comment>', $result->exitCode, $result->tail(3)));
        }
    }
}
