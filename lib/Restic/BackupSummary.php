<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Restic;

final class BackupSummary
{
    public function __construct(public readonly string $snapshotId, public readonly int $totalBytesProcessed) {}

    /** Parses the JSON lines that `restic backup --json` prints; the last summary line wins. */
    public static function fromJsonLines(string $output): self
    {
        $summary = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row) && ($row['message_type'] ?? '') === 'summary') {
                $summary = $row;
            }
        }
        if ($summary === null || !isset($summary['snapshot_id'])) {
            throw new ResticException('restic backup produced no summary line; output tail: ' . substr($output, -300));
        }
        return new self((string) $summary['snapshot_id'], (int) ($summary['total_bytes_processed'] ?? 0));
    }
}
