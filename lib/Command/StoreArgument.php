<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Config\Config;

final class StoreArgument
{
    /** @return list<string> */
    public static function parse(?string $value): array
    {
        return match ($value ?? 'all') {
            Config::STORE_DB => [Config::STORE_DB],
            Config::STORE_FILES => [Config::STORE_FILES],
            'all' => [Config::STORE_DB, Config::STORE_FILES],
            default => throw new \InvalidArgumentException(sprintf("Store must be db, files or all, got '%s'", $value)),
        };
    }
}
