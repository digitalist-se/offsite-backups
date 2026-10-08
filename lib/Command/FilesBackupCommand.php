<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Backup\BackupClass;
use Digitalist\OffsiteBackup\Backup\Excludes;
use Digitalist\OffsiteBackup\Backup\Inventory;
use Digitalist\OffsiteBackup\Backup\InventoryStore;
use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class FilesBackupCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('files:backup')->setDescription('Snapshot the configured file directories into the offsite restic repository');
    }

    protected function isGated(): bool
    {
        return true;
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $now = $this->now();
        $repo = $config->repositoryUrl(Config::STORE_FILES);
        $paths = array_map(static fn (string $p): string => rtrim($config->absolutePath($p), '/'), $config->filesPaths);
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                throw new \RuntimeException("Files path does not exist: $path");
            }
        }
        $restic = $this->restic($config);
        $restic->requireRepository($repo);
        $restic = $this->restic($config);
        $restic->requireRepository($repo);
        $host = $config->resticHost();
        $inventories = new InventoryStore($config->localDir);
        // One listing serves the class decision and the vanished-snapshot check.
        $before = $restic->snapshots($repo, ['host' => $host]);
        $decision = BackupClass::decide($now, $before);
        $class = $decision['class'];
        $excludes = Excludes::expand($paths, $config->filesExcludes);
        $baseline = $inventories->read(Config::STORE_FILES, $host);
        $vanished = $baseline === null ? [] : Inventory::of($before, $now, 'files:backup', $host)->regressionsSince($baseline);
        $unacknowledged = $baseline?->alert;
        $reporter->notice('Files backup started. repo={repo} paths={paths} tag={tag}', ['repo' => $repo, 'paths' => implode(',', $paths), 'tag' => $class]);
        if ($decision['reason'] !== null) {
            $reporter->notice('Class promoted to {class}: {reason}', ['class' => $class, 'reason' => $decision['reason']]);
        }
        $reporter->notice('Excluding {n} pattern(s): {patterns}', ['n' => count($excludes), 'patterns' => implode(' ', $excludes)]);
        if ($baseline !== null && $vanished !== []) {
            $reporter->error('Snapshots vanished since {when} ({by}): {what}. Backing up anyway; the run is reported as failed.', ['when' => $baseline->recordedAt->format(DATE_ATOM), 'by' => $baseline->recordedBy, 'what' => implode('; ', $vanished)]);
        } elseif ($unacknowledged !== null) {
            $reporter->error('Snapshot loss not acknowledged yet ({alert}). Backing up anyway; the run is reported as failed until `prune` resets the baseline or {file} is removed.', ['alert' => $unacknowledged, 'file' => $inventories->path(Config::STORE_FILES)]);
        }
        $summary = $restic->backupPaths($repo, $paths, $excludes, $class, $host);
        $reporter->notice('Restic backup completed: snapshot {id} ({bytes} bytes processed)', ['id' => substr($summary->snapshotId, 0, 8), 'bytes' => $summary->totalBytesProcessed]);

        $snapshot = $restic->snapshots($repo, ['tag' => $class, 'host' => $config->resticHost(), 'latest' => 1])[0] ?? null;
        $missing = $snapshot === null ? $paths : array_diff($paths, array_map(static fn (string $p): string => rtrim($p, '/'), $snapshot->paths));
        if ($snapshot === null || $snapshot->id !== $summary->snapshotId || $snapshot->time < $now->modify('-60 seconds') || $missing !== []) {
            throw new \RuntimeException(sprintf('Verification failed: latest %s snapshot for host %s is %s (expected %s created after %s covering %s)', $class, $config->resticHost(), $snapshot !== null ? $snapshot->shortId : 'none', substr($summary->snapshotId, 0, 8), $now->format(DATE_ATOM), implode(',', $paths)));
        }
        $reporter->notice('Verification succeeded: snapshot {short} created {time}', ['short' => $snapshot->shortId, 'time' => $snapshot->time->format(DATE_ATOM)]);

        $inventory = Inventory::of([...$before, $snapshot], $now, 'files:backup', $host);
        $reporter->report()->details = [
            'snapshot' => $snapshot->shortId,
            'snapshot_id' => $snapshot->id,
            'class' => $class,
            'paths' => $paths,
            'bytes' => $summary->totalBytesProcessed,
            'repository' => $repo,
            'inventory' => $inventory->toArray(),
        ];
        if ($baseline !== null && $vanished !== []) {
            // Keep the baseline the loss was measured against and record the loss on it: every run fails until a prune resets it.
            $inventories->write(Config::STORE_FILES, $baseline->withAlert($baseline->alert ?? sprintf('%s files:backup: %s', $now->format(DATE_ATOM), implode('; ', $vanished))));
            throw new \RuntimeException(sprintf('Snapshots vanished from %s since %s: %s. Snapshot %s was still created. The run keeps failing until `prune` resets the baseline or %s is removed', $repo, $baseline->recordedAt->format(DATE_ATOM), implode('; ', $vanished), $snapshot->shortId, $inventories->path(Config::STORE_FILES)));
        }
        if ($unacknowledged !== null) {
            throw new \RuntimeException(sprintf('Snapshot loss not acknowledged: %s. Snapshot %s was still created. Run `prune` to reset the baseline or remove %s', $unacknowledged, $snapshot->shortId, $inventories->path(Config::STORE_FILES)));
        }
        $inventories->write(Config::STORE_FILES, $inventory);
        $reporter->notice('Snapshot inventory: {inventory}', ['inventory' => $inventory->describe()]);
        $reporter->notice('Files backup completed successfully (snapshot {short})', ['short' => $snapshot->shortId]);
        return 0;
    }
}
