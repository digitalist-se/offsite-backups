<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Config;

final class Resolved
{
    /**
     * @param array<string,mixed> $values   final value per key (after placeholders)
     * @param array<string,string> $sources 'default' | 'file' | 'env' per key
     * @param list<string> $missing         required keys with no value
     */
    public function __construct(
        public readonly array $values,
        public readonly array $sources,
        public readonly array $missing,
        public readonly ?string $configFile,
        public readonly string $projectRoot,
    ) {}
}
