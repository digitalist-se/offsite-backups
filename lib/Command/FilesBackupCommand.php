<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Backup\BackupClass;
use Digitalist\OffsiteBackup\Backup\Excludes;
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
        $class = BackupClass::forDate($now);
        $repo = $config->repositoryUrl(Config::STORE_FILES);
        $paths = array_map(static fn (string $p): string => rtrim($config->absolutePath($p), '/'), $config->filesPaths);
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                throw new \RuntimeException("Files path does not exist: $path");
            }
        }
        $excludes = Excludes::expand($paths, $config->filesExcludes);
        $reporter->notice('Files backup started. repo={repo} paths={paths} tag={tag}', ['repo' => $repo, 'paths' => implode(',', $paths), 'tag' => $class]);
        $reporter->notice('Excluding {n} pattern(s): {patterns}', ['n' => count($excludes), 'patterns' => implode(' ', $excludes)]);

        $restic = $this->restic($config);
        $restic->requireRepository($repo);
        $summary = $restic->backupPaths($repo, $paths, $excludes, $class, $config->resticHost());
        $reporter->notice('Restic backup completed: snapshot {id} ({bytes} bytes processed)', ['id' => substr($summary->snapshotId, 0, 8), 'bytes' => $summary->totalBytesProcessed]);

        $snapshot = $restic->snapshots($repo, ['tag' => $class, 'host' => $config->resticHost(), 'latest' => 1])[0] ?? null;
        $missing = $snapshot === null ? $paths : array_diff($paths, array_map(static fn (string $p): string => rtrim($p, '/'), $snapshot->paths));
        if ($snapshot === null || $snapshot->id !== $summary->snapshotId || $snapshot->time < $now->modify('-60 seconds') || $missing !== []) {
            throw new \RuntimeException(sprintf('Verification failed: latest %s snapshot for host %s is %s (expected %s created after %s covering %s)', $class, $config->resticHost(), $snapshot !== null ? $snapshot->shortId : 'none', substr($summary->snapshotId, 0, 8), $now->format(DATE_ATOM), implode(',', $paths)));
        }
        $reporter->notice('Verification succeeded: snapshot {short} created {time}', ['short' => $snapshot->shortId, 'time' => $snapshot->time->format(DATE_ATOM)]);

        $reporter->report()->details = [
            'snapshot' => $snapshot->shortId,
            'snapshot_id' => $snapshot->id,
            'class' => $class,
            'paths' => $paths,
            'bytes' => $summary->totalBytesProcessed,
            'repository' => $repo,
        ];
        $reporter->notice('Files backup completed successfully (snapshot {short})', ['short' => $snapshot->shortId]);
        return 0;
    }
}
