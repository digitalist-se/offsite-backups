<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Platform;

final class ScheduledJob
{
    public function __construct(
        public readonly string $name,
        public readonly string $job,
        public readonly string $spec,
        public readonly string $command,
        public readonly ?\DateTimeImmutable $next,
    ) {}
}
