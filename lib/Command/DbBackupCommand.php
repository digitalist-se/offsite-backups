<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Backup\BackupClass;
use Digitalist\OffsiteBackup\Backup\BackupName;
use Digitalist\OffsiteBackup\Backup\DumpChecker;
use Digitalist\OffsiteBackup\Backup\Inventory;
use Digitalist\OffsiteBackup\Backup\InventoryStore;
use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DbBackupCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('db:backup')->setDescription('Dump the database and store it in the offsite restic repository');
    }

    protected function isGated(): bool
    {
        return true;
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $now = $this->now();
        $name = BackupName::dump($now, $config->project, $config->environment);
        // {file} is the path without ".gz": `drush sql:dump --gzip` appends the suffix itself.
        $dumpTarget = $config->localDir . '/' . $name;
        $localFile = $dumpTarget . '.gz';
        $repo = $config->repositoryUrl(Config::STORE_DB);
        $restic = $this->restic($config);
        $restic->requireRepository($repo);
        $host = $config->resticHost();
        $inventories = new InventoryStore($config->localDir);
        // One listing serves the class decision and the vanished-snapshot check.
        $before = $restic->snapshots($repo, ['host' => $host]);
        $decision = BackupClass::decide($now, $before);
        $class = $decision['class'];
        $baseline = $inventories->read(Config::STORE_DB, $host);
        $vanished = $baseline === null ? [] : Inventory::of($before, $now, 'db:backup', $host)->regressionsSince($baseline);
        $unacknowledged = $baseline?->alert;

        $this->prepareLocalDir($config->localDir, $reporter);
        $reporter->notice('Backup started. file={file} tag={tag} project={project} env={env}', ['file' => $localFile, 'tag' => $class, 'project' => $config->project, 'env' => $config->environment]);
        if ($decision['reason'] !== null) {
            $reporter->notice('Class promoted to {class}: {reason}', ['class' => $class, 'reason' => $decision['reason']]);
        }
        if ($baseline !== null && $vanished !== []) {
            $reporter->error('Snapshots vanished since {when} ({by}): {what}. Backing up anyway; the run is reported as failed.', ['when' => $baseline->recordedAt->format(DATE_ATOM), 'by' => $baseline->recordedBy, 'what' => implode('; ', $vanished)]);
        } elseif ($unacknowledged !== null) {
            $reporter->error('Snapshot loss not acknowledged yet ({alert}). Backing up anyway; the run is reported as failed until `prune` resets the baseline or {file} is removed.', ['alert' => $unacknowledged, 'file' => $inventories->path(Config::STORE_DB)]);
        }

        try {
            $reporter->notice('DB dump started: {file}', ['file' => $localFile]);
            $result = $this->runner()->run($this->dumpCommand($config), [
                'OB_DRUSH' => $config->absolutePath($config->drushBin),
                'OB_DRUPAL_ROOT' => $config->absolutePath($config->drushRoot),
                'OB_FILE' => $dumpTarget,
                'OB_STRUCTURE_TABLES' => implode(',', $config->dbStructureTables),
            ], null, 7200, null, $config->projectRoot);
            if (!$result->ok()) {
                throw new \RuntimeException(sprintf('DB dump failed (exit %d): %s', $result->exitCode, $result->tail(10)));
            }
            @chmod($localFile, 0600);

            $check = (new DumpChecker())->check($localFile, $config->dbMinBytes);
            $reporter->notice('DB dump completed: {file} ({compressed} bytes compressed, {raw} bytes raw, {tables} tables)', ['file' => $localFile, 'compressed' => $check->compressedBytes, 'raw' => $check->uncompressedBytes, 'tables' => $check->createTableCount]);

            $reporter->notice('Upload started: {repo}', ['repo' => $repo]);
            $summary = $restic->backupFromShell($repo, 'gzip -dc "$OB_DUMP_FILE"', ['OB_DUMP_FILE' => $localFile], $name, $class, $config->resticHost());
            $reporter->notice('Upload completed: snapshot {id} ({bytes} bytes processed)', ['id' => substr($summary->snapshotId, 0, 8), 'bytes' => $summary->totalBytesProcessed]);
            if ($summary->totalBytesProcessed !== $check->uncompressedBytes) {
                throw new \RuntimeException(sprintf('restic processed %d bytes but the dump holds %d; refusing to trust the snapshot', $summary->totalBytesProcessed, $check->uncompressedBytes));
            }

            $snapshot = $restic->snapshots($repo, ['tag' => $class, 'host' => $config->resticHost(), 'latest' => 1])[0] ?? null;
            if ($snapshot === null || $snapshot->id !== $summary->snapshotId || $snapshot->time < $now->modify('-60 seconds') || !in_array('/' . $name, $snapshot->paths, true)) {
                throw new \RuntimeException(sprintf('Verification failed: latest %s snapshot for host %s is %s, expected %s with path /%s created after %s', $class, $config->resticHost(), $snapshot !== null ? $snapshot->shortId : 'none', substr($summary->snapshotId, 0, 8), $name, $now->format(DATE_ATOM)));
            }
            $reporter->notice('Verification succeeded: snapshot {short} created {time} path {path}', ['short' => $snapshot->shortId, 'time' => $snapshot->time->format(DATE_ATOM), 'path' => $snapshot->paths[0]]);

            $inventory = Inventory::of([...$before, $snapshot], $now, 'db:backup', $host);
            $reporter->report()->details = [
                'name' => $name,
                'class' => $class,
                'snapshot' => $snapshot->shortId,
                'snapshot_id' => $snapshot->id,
                'bytes' => $check->uncompressedBytes,
                'compressed_bytes' => $check->compressedBytes,
                'tables' => $check->createTableCount,
                'repository' => $repo,
                'inventory' => $inventory->toArray(),
            ];
            if ($baseline !== null && $vanished !== []) {
                // Keep the baseline the loss was measured against and record the loss on it: every run fails until a prune resets it.
                $inventories->write(Config::STORE_DB, $baseline->withAlert($baseline->alert ?? sprintf('%s db:backup: %s', $now->format(DATE_ATOM), implode('; ', $vanished))));
                throw new \RuntimeException(sprintf('Snapshots vanished from %s since %s: %s. Snapshot %s was still created. The run keeps failing until `prune` resets the baseline or %s is removed', $repo, $baseline->recordedAt->format(DATE_ATOM), implode('; ', $vanished), $snapshot->shortId, $inventories->path(Config::STORE_DB)));
            }
            if ($unacknowledged !== null) {
                throw new \RuntimeException(sprintf('Snapshot loss not acknowledged: %s. Snapshot %s was still created. Run `prune` to reset the baseline or remove %s', $unacknowledged, $snapshot->shortId, $inventories->path(Config::STORE_DB)));
            }
            $inventories->write(Config::STORE_DB, $inventory);
            $reporter->notice('Snapshot inventory: {inventory}', ['inventory' => $inventory->describe()]);
            $reporter->notice('Backup process finished successfully: {name} (snapshot {short})', ['name' => $name, 'short' => $snapshot->shortId]);
            return 0;
        } finally {
            if (is_file($localFile)) {
                @unlink($localFile);
                $reporter->notice('Removed local dump {file}', ['file' => $localFile]);
            }
        }
    }

    /**
     * The operator template with placeholders mapped to "$VAR" references the
     * shell resolves. {file} carries no ".gz"; the command must write {file}.gz.
     */
    private function dumpCommand(Config $config): string
    {
        $template = $config->dbDumpCommand;
        if ($config->dbStructureTables === []) {
            $template = str_replace(' --structure-tables-list={structure_tables}', '', $template);
        }
        return strtr($template, [
            '{drush}' => '"$OB_DRUSH" --root="$OB_DRUPAL_ROOT"',
            '{file}' => '"$OB_FILE"',
            '{structure_tables}' => '"$OB_STRUCTURE_TABLES"',
        ]);
    }

    private function prepareLocalDir(string $dir, Reporter $reporter): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create local directory $dir");
        }
        foreach (glob($dir . '/*.sql.gz') ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                @unlink($file);
                $reporter->warning('Removed stale local file {file}', ['file' => $file]);
            }
        }
    }
}
