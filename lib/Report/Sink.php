<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Report;

interface Sink
{
    public function onStart(RunReport $report): void;

    public function onFailure(RunReport $report): void;

    public function onEnd(RunReport $report): void;
}
