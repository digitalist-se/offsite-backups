<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Restic;

/** What `restic stats --mode raw-data --json` reports for a whole repository. */
final class RepositoryStats
{
    public function __construct(
        public readonly int $totalSize,
        public readonly int $totalUncompressedSize,
        public readonly int $snapshotsCount,
    ) {}

    public static function fromJson(string $json): self
    {
        $row = json_decode($json, true);
        if (!is_array($row) || !array_key_exists('total_size', $row)) {
            throw new ResticException('restic stats did not return the expected JSON: ' . substr($json, 0, 200));
        }
        return new self(
            (int) $row['total_size'],
            (int) ($row['total_uncompressed_size'] ?? $row['total_size']),
            (int) ($row['snapshots_count'] ?? 0),
        );
    }
}
