<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Restic;

final class Snapshot
{
    /**
     * @param list<string> $paths
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $shortId,
        public readonly \DateTimeImmutable $time,
        public readonly string $hostname,
        public readonly array $paths,
        public readonly array $tags,
        public readonly ?int $totalBytesProcessed,
    ) {}

    /** @return list<Snapshot> */
    public static function listFromJson(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ResticException('restic snapshots did not return a JSON array: ' . substr($json, 0, 200));
        }
        return array_map(static fn (array $row): self => self::fromArray($row), array_values($data));
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['short_id'] ?? substr((string) ($row['id'] ?? ''), 0, 8)),
            self::parseTime((string) ($row['time'] ?? '')),
            (string) ($row['hostname'] ?? ''),
            array_values(array_map('strval', (array) ($row['paths'] ?? []))),
            array_values(array_map('strval', (array) ($row['tags'] ?? []))),
            isset($row['summary']['total_bytes_processed']) ? (int) $row['summary']['total_bytes_processed'] : null,
        );
    }

    /** restic prints nanoseconds; PHP parses at most microseconds. */
    public static function parseTime(string $time): \DateTimeImmutable
    {
        $trimmed = preg_replace('/(\.\d{6})\d+/', '$1', $time) ?? $time;
        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Exception $e) {
            throw new ResticException("Cannot parse snapshot time '$time'", 0, $e);
        }
    }

    public function ageSeconds(\DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $this->time->getTimestamp();
    }
}
