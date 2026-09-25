<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Report;

use Digitalist\OffsiteBackup\Report\RunReport;
use PHPUnit\Framework\TestCase;

final class RunReportTest extends TestCase
{
    public function testLifecycleAndStatePayload(): void
    {
        $start = new \DateTimeImmutable('2026-09-25 01:00:00', new \DateTimeZone('UTC'));
        $report = new RunReport('db:backup', 'site', 'main', $start);
        $report->addMessage('notice', 'Backup started');
        $report->addMessage('error', 'boom');
        self::assertSame(2, $report->count());
        self::assertSame([['level' => 'error', 'message' => 'boom']], array_map(static fn ($m) => ['level' => $m['level'], 'message' => $m['message']], $report->messagesSince(1)));
        self::assertSame('running', $report->outcome);

        $report->details['snapshot'] = 'abc';
        $report->fail('boom');
        self::assertSame('failure', $report->outcome);
        self::assertSame('boom', $report->error);
        self::assertNotNull($report->finishedAt);

        $array = $report->toArray();
        self::assertSame('db:backup', $array['command']);
        self::assertSame('site', $array['project']);
        self::assertSame('main', $array['environment']);
        self::assertSame('2026-09-25T01:00:00+00:00', $array['started']);
        self::assertSame('failure', $array['outcome']);
        self::assertSame(['snapshot' => 'abc'], $array['details']);
        self::assertSame('boom', $array['error']);
        self::assertIsInt($array['duration_seconds']);
        self::assertArrayNotHasKey('messages', $array);
    }

    public function testSkipAndSucceed(): void
    {
        $a = new RunReport('prune', 'p', 'e');
        $a->skip('environment type "development"');
        self::assertSame('skipped', $a->outcome);
        self::assertSame('environment type "development"', $a->error);
        $b = new RunReport('prune', 'p', 'e');
        $b->succeed();
        self::assertSame('success', $b->outcome);
        self::assertNull($b->error);
    }
}
