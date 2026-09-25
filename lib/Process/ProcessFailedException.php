<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Process;

final class ProcessFailedException extends \RuntimeException
{
    public function __construct(public readonly ProcessResult $result, string $what)
    {
        parent::__construct(sprintf('%s failed (exit %d): %s', $what, $result->exitCode, $result->tail(10)));
    }
}
