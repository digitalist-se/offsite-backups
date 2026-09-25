<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

final class BackupName
{
    public static function dump(\DateTimeImmutable $date, string $project, string $environment): string
    {
        return sprintf('%s-%s-%s.sql', $date->format('Y-m-d'), $project, $environment);
    }

    public static function dateOf(string $name): ?\DateTimeImmutable
    {
        if (preg_match('#(?:^|/)(\d{4}-\d{2}-\d{2})-[^/]+\.sql$#', $name, $m) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
        return $date === false ? null : $date;
    }
}
