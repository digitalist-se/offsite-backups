<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Process;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly string $commandLine,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** Last lines of stdout and stderr combined, for log messages. */
    public function tail(int $lines = 20): string
    {
        $all = trim(rtrim($this->stdout, "\n") . "\n" . $this->stderr);
        $parts = $all === '' ? [] : explode("\n", $all);
        return implode("\n", array_slice($parts, -$lines));
    }
}
