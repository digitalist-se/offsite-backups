<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Restic\SnapshotResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class FilesRestoreCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('files:restore')
            ->setDescription('Restore one path of a files snapshot into a directory (flattened)')
            ->addOption('snapshot', null, InputOption::VALUE_REQUIRED, '"latest" or a snapshot id prefix', 'latest')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Directory to restore into')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Snapshot path to restore (default: the first recorded path)');
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $targetOption = $input->getOption('target');
        if (!is_string($targetOption) || $targetOption === '') {
            throw new \RuntimeException('--target is required');
        }
        $target = rtrim($config->absolutePath($targetOption), '/');
        $repo = $config->repositoryUrl(Config::STORE_FILES);
        $restic = $this->restic($config);
        $snapshot = SnapshotResolver::resolve($restic, $repo, $config->resticHost(), (string) $input->getOption('snapshot'));
        $path = $input->getOption('path');
        $path = is_string($path) && $path !== '' ? rtrim($path, '/') : rtrim($snapshot->paths[0] ?? '', '/');
        if ($path === '' || !in_array($path, array_map(static fn (string $p): string => rtrim($p, '/'), $snapshot->paths), true)) {
            throw new \RuntimeException(sprintf("Path '%s' is not in snapshot %s (paths: %s)", $path, $snapshot->shortId, implode(', ', $snapshot->paths)));
        }

        if (!is_dir($config->localDir) && !mkdir($config->localDir, 0700, true) && !is_dir($config->localDir)) {
            throw new \RuntimeException("Cannot create {$config->localDir}");
        }
        $tmp = $config->localDir . '/restore-' . bin2hex(random_bytes(4));
        $reporter->notice('Restoring {path} from snapshot {short} ({time}) into {target}', ['path' => $path, 'short' => $snapshot->shortId, 'time' => $snapshot->time->format(DATE_ATOM), 'target' => $target]);
        try {
            $restic->restore($repo, $snapshot->id, $tmp, $path);
            $source = $tmp . $path;
            if (!is_dir($source)) {
                throw new \RuntimeException("restic restored nothing at $source");
            }
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new \RuntimeException("Cannot create $target");
            }
            $copy = $this->runner()->run(['cp', '-a', $source . '/.', $target . '/'], [], null, 7200);
            if (!$copy->ok()) {
                throw new \RuntimeException('Copy into target failed: ' . $copy->tail(5));
            }
        } finally {
            $this->runner()->run(['rm', '-rf', $tmp], [], null, 600);
        }
        $reporter->notice('Restored {path} from snapshot {short} into {target}', ['path' => $path, 'short' => $snapshot->shortId, 'target' => $target]);
        return 0;
    }
}
