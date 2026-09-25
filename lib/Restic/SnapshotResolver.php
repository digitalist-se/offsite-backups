<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Restic;

final class SnapshotResolver
{
    /** "latest" or an id prefix, always within the configured host. */
    public static function resolve(Restic $restic, string $repositoryUrl, string $host, string $selector): Snapshot
    {
        $selector = trim($selector);
        if ($selector === '' || $selector === 'latest') {
            $latest = $restic->snapshots($repositoryUrl, ['host' => $host, 'latest' => 1])[0] ?? null;
            if ($latest === null) {
                throw new ResticException("No snapshot for host $host in $repositoryUrl");
            }
            return $latest;
        }
        foreach ($restic->snapshots($repositoryUrl, ['host' => $host]) as $snapshot) {
            if (str_starts_with($snapshot->id, $selector)) {
                return $snapshot;
            }
        }
        throw new ResticException("No snapshot matching '$selector' for host $host in $repositoryUrl");
    }
}
