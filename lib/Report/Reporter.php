<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Report;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\Console\Output\OutputInterface;

/** PSR-3 logger that prints to the console, records into the RunReport and drives the sinks. */
final class Reporter implements LoggerInterface
{
    use LoggerTrait;

    /** @param list<Sink> $sinks */
    public function __construct(
        private readonly RunReport $report,
        private readonly OutputInterface $output,
        private readonly array $sinks,
    ) {}

    /** @param array<string,mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $text = self::interpolate((string) $message, $context);
        $this->report->addMessage((string) $level, $text);
        $this->output->writeln(sprintf('[%s] %-7s %s', date('Y-m-d H:i:s'), strtoupper((string) $level), $text));
    }

    public function report(): RunReport
    {
        return $this->report;
    }

    public function start(): void
    {
        $this->each('onStart');
    }

    public function failure(): void
    {
        $this->each('onFailure');
    }

    public function finish(): void
    {
        $this->each('onEnd');
    }

    private function each(string $method): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->$method($this->report);
            } catch (\Throwable $e) {
                $this->output->writeln(sprintf('<comment>%s sink failed: %s</comment>', (new \ReflectionClass($sink))->getShortName(), $e->getMessage()));
            }
        }
    }

    /** @param array<string,mixed> $context */
    private static function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replace);
    }
}
