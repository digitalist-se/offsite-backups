<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

use Digitalist\OffsiteBackup\Restic\Snapshot;

/**
 * What a repository held for this host at one moment: snapshots per class,
 * the total and the oldest one. Recorded after each successful run and
 * compared on the next, so snapshots removed outside a prune are noticed.
 * A detected loss is recorded on the baseline (`alert`) and stays until a
 * prune rewrites it, so the failure repeats instead of turning green the
 * night the repository is back at the old count.
 */
final class Inventory
{
    /** @param array<string,int> $counts class => snapshots */
    public function __construct(
        public readonly array $counts,
        public readonly int $total,
        public readonly ?\DateTimeImmutable $oldest,
        public readonly \DateTimeImmutable $recordedAt,
        public readonly string $recordedBy,
        public readonly string $host = '',
        public readonly ?string $alert = null,
    ) {}

    /** @param list<Snapshot> $snapshots */
    public static function of(array $snapshots, \DateTimeImmutable $now, string $by, string $host = ''): self
    {
        $counts = array_fill_keys(BackupClass::ALL, 0);
        $oldest = null;
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->tags as $tag) {
                if (array_key_exists($tag, $counts)) {
                    $counts[$tag]++;
                }
            }
            if ($oldest === null || $snapshot->time < $oldest) {
                $oldest = $snapshot->time;
            }
        }
        return new self($counts, count($snapshots), $oldest, $now, $by, $host);
    }

    /** The same baseline with an unacknowledged loss recorded on it. */
    public function withAlert(string $alert): self
    {
        return new self($this->counts, $this->total, $this->oldest, $this->recordedAt, $this->recordedBy, $this->host, $alert);
    }

    /**
     * What shrank since $previous: fewer snapshots in a class, or an oldest
     * snapshot younger than before, which means older ones are gone. Times are
     * compared to the second: restic reports nanoseconds, the stored baseline
     * keeps seconds.
     * @return list<string>
     */
    public function regressionsSince(self $previous): array
    {
        $out = [];
        foreach (BackupClass::ALL as $class) {
            $was = $previous->counts[$class] ?? 0;
            $is = $this->counts[$class] ?? 0;
            if ($is < $was) {
                $out[] = sprintf('%s: %d before, %d now', $class, $was, $is);
            }
        }
        if ($previous->oldest !== null && ($this->oldest === null || $this->oldest->getTimestamp() > $previous->oldest->getTimestamp())) {
            $out[] = sprintf('oldest snapshot was %s, now %s', $previous->oldest->format('Y-m-d H:i:s'), $this->oldest?->format('Y-m-d H:i:s') ?? 'none');
        }
        return $out;
    }

    public function describe(): string
    {
        $parts = [];
        foreach (BackupClass::ALL as $class) {
            $parts[] = sprintf('%s %d', $class, $this->counts[$class] ?? 0);
        }
        return implode(', ', $parts) . ', oldest ' . ($this->oldest?->format('Y-m-d') ?? 'none');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts,
            'total' => $this->total,
            'oldest' => $this->oldest?->format(DATE_ATOM),
            'recorded' => $this->recordedAt->format(DATE_ATOM),
            'by' => $this->recordedBy,
            'host' => $this->host,
            'alert' => $this->alert,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $rawCounts = $data['counts'] ?? null;
        $recorded = $data['recorded'] ?? null;
        if (!is_array($rawCounts) || !is_string($recorded)) {
            return null;
        }
        try {
            $recordedAt = new \DateTimeImmutable($recorded);
            $oldest = is_string($data['oldest'] ?? null) ? new \DateTimeImmutable($data['oldest']) : null;
        } catch (\Exception) {
            return null;
        }
        $counts = [];
        foreach (BackupClass::ALL as $class) {
            $counts[$class] = is_numeric($rawCounts[$class] ?? null) ? (int) $rawCounts[$class] : 0;
        }
        $total = $data['total'] ?? 0;
        $by = $data['by'] ?? '';
        $host = $data['host'] ?? '';
        $alert = $data['alert'] ?? null;
        return new self($counts, is_numeric($total) ? (int) $total : 0, $oldest, $recordedAt, is_string($by) ? $by : '', is_string($host) ? $host : '', is_string($alert) && $alert !== '' ? $alert : null);
    }
}
