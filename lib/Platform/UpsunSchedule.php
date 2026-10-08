<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Platform;

use Cron\CronExpression;
use Digitalist\OffsiteBackup\Environment;

/**
 * The offsite-backup crons of the running app, from the definition Upsun
 * (and Platform.sh) expose as base64 JSON in PLATFORM_APPLICATION. Both
 * platforms set the cron timezone at the app level; a per-cron key wins.
 */
final class UpsunSchedule
{
    /** CLI command => job key, in the order the jobs run during a day. */
    public const JOBS = ['db:backup' => 'db_backup', 'files:backup' => 'files_backup', 'prune' => 'prune', 'check' => 'check'];

    /** @return list<ScheduledJob> empty when the platform exposes nothing usable */
    public static function fromEnvironment(Environment $env, \DateTimeImmutable $now): array
    {
        $raw = $env->get('PLATFORM_APPLICATION');
        if ($raw === null) {
            return [];
        }
        $decoded = base64_decode($raw, true);
        $application = $decoded === false ? null : json_decode($decoded, true);
        return is_array($application) ? self::fromApplication($application, $now) : [];
    }

    /**
     * @param array<string,mixed> $application decoded app definition
     * @return list<ScheduledJob>
     */
    public static function fromApplication(array $application, \DateTimeImmutable $now): array
    {
        $crons = is_array($application['crons'] ?? null) ? $application['crons'] : [];
        $jobs = [];
        foreach ($crons as $name => $cron) {
            if (!is_array($cron)) {
                continue;
            }
            $command = (string) ($cron['commands']['start'] ?? $cron['cmd'] ?? '');
            if (!preg_match('/offsite-backup\S*\s+(' . implode('|', array_map('preg_quote', array_keys(self::JOBS))) . ')(?=\s|$)/', $command, $m)) {
                continue;
            }
            $spec = (string) ($cron['spec'] ?? '');
            $jobs[self::JOBS[$m[1]]] = new ScheduledJob((string) $name, self::JOBS[$m[1]], $spec, $command, self::next($spec, (string) ($cron['timezone'] ?? $application['timezone'] ?? 'UTC'), $now));
        }
        $ordered = [];
        foreach (self::JOBS as $job) {
            if (isset($jobs[$job])) {
                $ordered[] = $jobs[$job];
            }
        }
        return $ordered;
    }

    private static function next(string $spec, string $timezone, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        try {
            $next = (new CronExpression($spec))->getNextRunDate($now, 0, false, $timezone);
        } catch (\Throwable) {
            return null;
        }
        return \DateTimeImmutable::createFromInterface($next);
    }
}
