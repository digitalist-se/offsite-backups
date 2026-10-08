<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

use Digitalist\OffsiteBackup\Restic\Snapshot;

final class BackupClass
{
    public const DAILY = 'daily';
    public const BIWEEKLY = 'biweekly';
    public const MONTHLY = 'monthly';
    public const ALL = [self::DAILY, self::BIWEEKLY, self::MONTHLY];

    /** The class the calendar gives a run: day 01 monthly, day 15 biweekly, otherwise daily. */
    public static function forDate(\DateTimeImmutable $date): string
    {
        return match ($date->format('d')) {
            '01' => self::MONTHLY,
            '15' => self::BIWEEKLY,
            default => self::DAILY,
        };
    }

    /**
     * The class a run gets, given what the repository already holds for this
     * host. The calendar decides first. A month without a monthly snapshot gets
     * one from the next successful run, whatever the day; from the 15th on, a
     * month without a biweekly snapshot gets one the same way. A failed night
     * on the 1st or the 15th then costs a day, not the month's long-term snapshot.
     *
     * @param list<Snapshot> $existing snapshots of this host, any class
     * @return array{class: string, reason: ?string} reason is set when the calendar class was promoted
     */
    public static function decide(\DateTimeImmutable $now, array $existing): array
    {
        $byDate = self::forDate($now);
        if ($byDate === self::MONTHLY) {
            return ['class' => self::MONTHLY, 'reason' => null];
        }
        $month = $now->format('Y-m');
        $zone = $now->getTimezone();
        if (!self::has($existing, self::MONTHLY, $month, $zone)) {
            return ['class' => self::MONTHLY, 'reason' => sprintf('no %s snapshot for %s yet (calendar class %s)', self::MONTHLY, $month, $byDate)];
        }
        if ($byDate === self::BIWEEKLY) {
            return ['class' => self::BIWEEKLY, 'reason' => null];
        }
        if ((int) $now->format('d') > 15 && !self::has($existing, self::BIWEEKLY, $month, $zone)) {
            return ['class' => self::BIWEEKLY, 'reason' => sprintf('no %s snapshot for %s yet (calendar class %s)', self::BIWEEKLY, $month, $byDate)];
        }
        return ['class' => self::DAILY, 'reason' => null];
    }

    /** @param list<Snapshot> $snapshots */
    private static function has(array $snapshots, string $class, string $month, \DateTimeZone $zone): bool
    {
        foreach ($snapshots as $snapshot) {
            if (in_array($class, $snapshot->tags, true) && $snapshot->time->setTimezone($zone)->format('Y-m') === $month) {
                return true;
            }
        }
        return false;
    }
}
