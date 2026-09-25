<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs external commands. Array commands become argv (no shell). String
 * commands run through the shell with "$VAR" placeholders resolved from $env,
 * which keeps operator-provided templates free of quoting bugs.
 */
final class ProcessRunner
{
    /**
     * @param list<string>|string $command
     * @param array<string,string> $env  added on top of the inherited environment
     * @param callable(string):void|null $onStdout  when given, stdout is streamed here instead of captured
     */
    public function run(array|string $command, array $env = [], ?string $input = null, ?int $timeout = 3600, ?callable $onStdout = null, ?string $cwd = null): ProcessResult
    {
        $process = is_array($command)
            ? new Process($command, $cwd, $env, $input, $timeout)
            : Process::fromShellCommandline($command, $cwd, $env, $input, $timeout);
        $stdout = '';
        $stderr = '';
        $callback = static function (string $type, string $buffer) use (&$stdout, &$stderr, $onStdout): void {
            if ($type === Process::OUT) {
                if ($onStdout !== null) {
                    $onStdout($buffer);
                } else {
                    $stdout .= $buffer;
                }
            } else {
                $stderr .= $buffer;
            }
        };
        try {
            $process->run($callback);
        } catch (ProcessTimedOutException $e) {
            // A hung external tool is a failure like any other, reported with its output so far.
            return new ProcessResult(124, $stdout, rtrim($stderr, "\n") . "\n" . sprintf('Process timed out after %d seconds', (int) ($timeout ?? 0)), $process->getCommandLine());
        }
        return new ProcessResult($process->getExitCode() ?? -1, $stdout, $stderr, $process->getCommandLine());
    }
}
