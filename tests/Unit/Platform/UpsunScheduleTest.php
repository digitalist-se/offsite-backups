<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Platform;

use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Platform\ScheduledJob;
use Digitalist\OffsiteBackup\Platform\UpsunSchedule;
use PHPUnit\Framework\TestCase;

final class UpsunScheduleTest extends TestCase
{
    private const APPLICATION = [
        'name' => 'site',
        'crons' => [
            'drupal' => ['spec' => '*/19 * * * *', 'commands' => ['start' => 'cd /app/web && ../vendor/bin/drush cron']],
            'check' => ['spec' => '0 5 * * 0', 'commands' => ['start' => 'cd /app && vendor/bin/offsite-backup check']],
            'db_backup' => ['spec' => '0 1 * * *', 'commands' => ['start' => 'cd /app && vendor/bin/offsite-backup db:backup']],
            'files_backup' => ['spec' => '0 2 * * *', 'commands' => ['start' => 'cd /app && vendor/bin/offsite-backup files:backup']],
            'prune' => ['spec' => '0 3 * * 0', 'commands' => ['start' => 'cd /app && vendor/bin/offsite-backup prune --dry-run=0']],
        ],
    ];

    private static function environment(?string $application): Environment
    {
        return new Environment($application === null ? [] : ['PLATFORM_APPLICATION' => $application]);
    }

    public function testReadsTheOffsiteBackupCronsInJobOrderWithTheirNextRun(): void
    {
        // A Thursday.
        $now = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC'));
        $jobs = UpsunSchedule::fromEnvironment(self::environment(base64_encode((string) json_encode(self::APPLICATION))), $now);

        self::assertContainsOnlyInstancesOf(ScheduledJob::class, $jobs);
        self::assertSame(['db_backup', 'files_backup', 'prune', 'check'], array_map(static fn (ScheduledJob $j): string => $j->job, $jobs));
        self::assertSame('0 1 * * *', $jobs[0]->spec);
        self::assertSame('cd /app && vendor/bin/offsite-backup db:backup', $jobs[0]->command);
        self::assertSame('2026-10-02 01:00 UTC', $jobs[0]->next?->format('Y-m-d H:i T'));
        self::assertSame('2026-10-04 03:00 UTC', $jobs[2]->next?->format('Y-m-d H:i T'), 'next Sunday');
    }

    public function testCronTimezoneIsHonoured(): void
    {
        $application = self::APPLICATION;
        $application['crons'] = ['db_backup' => ['spec' => '0 1 * * *', 'timezone' => 'Europe/Stockholm', 'commands' => ['start' => 'vendor/bin/offsite-backup db:backup']]];
        $now = new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('UTC'));
        $jobs = UpsunSchedule::fromEnvironment(self::environment(base64_encode((string) json_encode($application))), $now);
        self::assertSame('2026-10-01 23:00 UTC', $jobs[0]->next?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i T'));
    }

    public function testNothingWithoutThePlatformVariableOrWithGarbage(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 10:00:00');
        self::assertSame([], UpsunSchedule::fromEnvironment(self::environment(null), $now));
        self::assertSame([], UpsunSchedule::fromEnvironment(self::environment('not base64 json'), $now));
        self::assertSame([], UpsunSchedule::fromEnvironment(self::environment(base64_encode('{"crons":{}}')), $now));
    }

    public function testAnInvalidSpecKeepsTheJobWithoutANextRun(): void
    {
        $application = ['crons' => ['db_backup' => ['spec' => 'every night', 'commands' => ['start' => 'vendor/bin/offsite-backup db:backup']]]];
        $jobs = UpsunSchedule::fromEnvironment(self::environment(base64_encode((string) json_encode($application))), new \DateTimeImmutable('2026-10-01 10:00:00'));
        self::assertCount(1, $jobs);
        self::assertNull($jobs[0]->next);
    }
}
