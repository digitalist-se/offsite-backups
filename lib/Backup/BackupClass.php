<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

final class BackupClass
{
    public const DAILY = 'daily';
    public const BIWEEKLY = 'biweekly';
    public const MONTHLY = 'monthly';
    public const ALL = [self::DAILY, self::BIWEEKLY, self::MONTHLY];

    public static function forDate(\DateTimeImmutable $date): string
    {
        return match ($date->format('d')) {
            '01' => self::MONTHLY,
            '15' => self::BIWEEKLY,
            default => self::DAILY,
        };
    }
}
