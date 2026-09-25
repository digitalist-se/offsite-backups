<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

final class Duration
{
    /** Parses "26h", "90m", "2d", "30s" or plain seconds. */
    public static function parse(string $value): int
    {
        $value = strtolower(trim($value));
        if (preg_match('/^(\d+)\s*([smhd]?)$/', $value, $m) !== 1) {
            throw new \InvalidArgumentException("Invalid duration '$value'; use e.g. 26h, 90m, 2d or seconds");
        }
        $n = (int) $m[1];
        return match ($m[2]) {
            'd' => $n * 86400,
            'h' => $n * 3600,
            'm' => $n * 60,
            default => $n,
        };
    }

    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds >= 86400) {
            return sprintf('%dd %dh', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
        }
        if ($seconds >= 3600) {
            return sprintf('%dh %dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        return sprintf('%dm', intdiv($seconds, 60));
    }
}
