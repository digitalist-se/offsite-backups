<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

final class DumpCheckResult
{
    public function __construct(
        public readonly int $compressedBytes,
        public readonly int $uncompressedBytes,
        public readonly int $createTableCount,
    ) {}
}
