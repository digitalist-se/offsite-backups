<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Backup\BackupClass;
use Digitalist\OffsiteBackup\Backup\BackupName;
use Digitalist\OffsiteBackup\Backup\DumpChecker;
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
        $class = BackupClass::forDate($now);
        $name = BackupName::dump($now, $config->project, $config->environment);
        $localFile = $config->localDir . '/' . $name . '.gz';
        $repo = $config->repositoryUrl(Config::STORE_DB);
        $restic = $this->restic($config);

        $this->prepareLocalDir($config->localDir, $reporter);
        $reporter->notice('Backup started. file={file} tag={tag} project={project} env={env}', ['file' => $localFile, 'tag' => $class, 'project' => $config->project, 'env' => $config->environment]);

        try {
            $reporter->notice('DB dump started: {file}', ['file' => $localFile]);
            $result = $this->runner()->run($this->dumpCommand($config), [
                'OB_DRUSH' => $config->absolutePath($config->drushBin),
                'OB_DRUPAL_ROOT' => $config->absolutePath($config->drushRoot),
                'OB_FILE' => $localFile,
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

            $reporter->report()->details = [
                'name' => $name,
                'class' => $class,
                'snapshot' => $snapshot->shortId,
                'snapshot_id' => $snapshot->id,
                'bytes' => $check->uncompressedBytes,
                'compressed_bytes' => $check->compressedBytes,
                'tables' => $check->createTableCount,
                'repository' => $repo,
            ];
            $reporter->notice('Backup process finished successfully: {name} (snapshot {short})', ['name' => $name, 'short' => $snapshot->shortId]);
            return 0;
        } finally {
            if (is_file($localFile)) {
                @unlink($localFile);
                $reporter->notice('Removed local dump {file}', ['file' => $localFile]);
            }
        }
    }

    /** The operator template with placeholders mapped to "$VAR" references the shell resolves. */
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
