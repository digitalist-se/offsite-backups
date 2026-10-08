<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Tests\Unit\Backup;

use Digitalist\OffsiteBackup\Backup\Inventory;
use Digitalist\OffsiteBackup\Restic\Snapshot;
use PHPUnit\Framework\TestCase;

final class InventoryTest extends TestCase
{
    private static function snapshot(string $tag, string $time): Snapshot
    {
        return new Snapshot(md5($tag . $time), substr(md5($tag . $time), 0, 8), new \DateTimeImmutable($time), 'proj-main', ['/x.sql'], [$tag], null);
    }

    /** @return list<Snapshot> */
    private static function week(): array
    {
        $out = [self::snapshot('monthly', '2026-09-01 01:00:00'), self::snapshot('biweekly', '2026-09-15 01:00:00')];
        foreach (range(2, 8) as $d) {
            $out[] = self::snapshot('daily', sprintf('2026-10-%02d 01:00:00', $d));
        }
        return $out;
    }

    public function testCountsClassesTotalAndOldest(): void
    {
        $inventory = Inventory::of(self::week(), new \DateTimeImmutable('2026-10-08 01:05:00'), 'db:backup');
        self::assertSame(['daily' => 7, 'biweekly' => 1, 'monthly' => 1], $inventory->counts);
        self::assertSame(9, $inventory->total);
        self::assertSame('2026-09-01', $inventory->oldest?->format('Y-m-d'));
        self::assertSame('daily 7, biweekly 1, monthly 1, oldest 2026-09-01', $inventory->describe());
        self::assertSame(['daily' => 0, 'biweekly' => 0, 'monthly' => 0], Inventory::of([], new \DateTimeImmutable(), 'x')->counts);
    }

    public function testRegressionsNameTheClassesThatShrankAndALostOldest(): void
    {
        $now = new \DateTimeImmutable('2026-10-09 01:05:00');
        $before = Inventory::of(self::week(), $now, 'db:backup');
        $shrunk = array_values(array_filter(self::week(), static fn (Snapshot $s): bool => !in_array('monthly', $s->tags, true) && $s->time->format('Y-m-d') !== '2026-10-02'));
        $after = Inventory::of($shrunk, $now, 'db:backup');
        self::assertSame(['daily: 7 before, 6 now', 'monthly: 1 before, 0 now', 'oldest snapshot was 2026-09-01 01:00:00, now 2026-09-15 01:00:00'], $after->regressionsSince($before));
    }

    public function testGrowthAndANormalRotationAreNotRegressions(): void
    {
        $now = new \DateTimeImmutable('2026-10-09 01:05:00');
        $before = Inventory::of(self::week(), $now, 'db:backup');
        // A baseline read back from JSON has lost the sub-second part restic reports; that is not a regression.
        $precise = [...self::week(), self::snapshot('daily', '2026-10-09 01:00:00.734512')];
        $precise[0] = new Snapshot('m', 'm', new \DateTimeImmutable('2026-09-01 01:00:00.512345'), 'proj-main', ['/x.sql'], ['monthly'], null);
        $rounded = Inventory::fromArray(Inventory::of($precise, $now, 'db:backup')->toArray());
        self::assertNotNull($rounded);
        self::assertSame([], Inventory::of($precise, $now, 'db:backup')->regressionsSince($rounded));
        $grown = [...self::week(), self::snapshot('daily', '2026-10-09 01:00:00')];
        self::assertSame([], Inventory::of($grown, $now, 'db:backup')->regressionsSince($before));
        // The repository emptied entirely is the worst regression.
        self::assertSame(['daily: 7 before, 0 now', 'biweekly: 1 before, 0 now', 'monthly: 1 before, 0 now', 'oldest snapshot was 2026-09-01 01:00:00, now none'], Inventory::of([], $now, 'db:backup')->regressionsSince($before));
    }

    public function testRoundTripsThroughArraysAndRejectsGarbage(): void
    {
        $inventory = Inventory::of(self::week(), new \DateTimeImmutable('2026-10-08 01:05:00+02:00'), 'prune');
        $copy = Inventory::fromArray($inventory->toArray());
        self::assertNotNull($copy);
        self::assertSame($inventory->counts, $copy->counts);
        self::assertSame($inventory->total, $copy->total);
        self::assertSame($inventory->oldest?->getTimestamp(), $copy->oldest?->getTimestamp());
        self::assertSame($inventory->recordedAt->getTimestamp(), $copy->recordedAt->getTimestamp());
        self::assertSame('prune', $copy->recordedBy);
        self::assertNull(Inventory::fromArray(['counts' => 'no']));
        self::assertNull(Inventory::fromArray(['counts' => [], 'recorded' => 'not a date']));
        $partial = Inventory::fromArray(['counts' => ['daily' => '3'], 'recorded' => '2026-10-08T01:05:00+02:00']);
        self::assertSame(['daily' => 3, 'biweekly' => 0, 'monthly' => 0], $partial?->counts);
        self::assertNull($partial?->oldest);
    }
    public function testTheHostAndAnUnacknowledgedLossTravelWithTheBaseline(): void
    {
        $now = new \DateTimeImmutable('2026-10-08 01:05:00+00:00');
        $inventory = Inventory::of(self::week(), $now, 'db:backup', 'proj-main');
        self::assertSame('proj-main', $inventory->host);
        self::assertNull($inventory->alert);

        $flagged = $inventory->withAlert('2026-10-09T01:05:00+00:00 db:backup: daily: 7 before, 6 now');
        self::assertSame($inventory->counts, $flagged->counts, 'the baseline itself is unchanged');
        self::assertSame($inventory->recordedAt, $flagged->recordedAt);
        self::assertSame('2026-10-09T01:05:00+00:00 db:backup: daily: 7 before, 6 now', $flagged->alert);

        $copy = Inventory::fromArray($flagged->toArray());
        self::assertSame('proj-main', $copy?->host);
        self::assertSame($flagged->alert, $copy?->alert);
        $legacy = Inventory::fromArray(['counts' => ['daily' => 7], 'recorded' => '2026-10-08T01:05:00+00:00', 'alert' => '']);
        self::assertSame('', $legacy?->host, 'files written before the host was recorded');
        self::assertNull($legacy?->alert, 'an empty alert is no alert');
    }
}
