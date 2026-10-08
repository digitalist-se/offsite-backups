<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

/**
 * Turns the configured exclude patterns into restic arguments. An entry with
 * `{path}` is expanded once per backed-up path and anchored there; any other
 * entry goes to restic verbatim, where a pattern without a leading `/` matches
 * a component of that name at any depth below the backed-up paths.
 */
final class Excludes
{
    public const PLACEHOLDER = '{path}';

    /**
     * @param list<string> $paths absolute paths being backed up
     * @param list<string> $patterns configured entries
     * @return list<string> the --exclude values, in order, without duplicates
     */
    public static function expand(array $paths, array $patterns): array
    {
        $out = [];
        foreach ($patterns as $pattern) {
            if (str_contains($pattern, self::PLACEHOLDER)) {
                foreach ($paths as $path) {
                    $out[] = strtr($pattern, [self::PLACEHOLDER => rtrim($path, '/')]);
                }
            } else {
                $out[] = $pattern;
            }
        }
        return array_values(array_unique($out));
    }
}
