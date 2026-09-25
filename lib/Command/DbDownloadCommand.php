<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Restic\SnapshotResolver;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DbDownloadCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('db:download')
            ->setDescription('Download one database dump snapshot as a .sql.gz file')
            ->addArgument('snapshot', InputArgument::OPTIONAL, '"latest" or a snapshot id prefix', 'latest')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target directory', '.');
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $repo = $config->repositoryUrl(Config::STORE_DB);
        $restic = $this->restic($config);
        $restic->requireRepository($repo);
        $snapshot = SnapshotResolver::resolve($restic, $repo, $config->resticHost(), (string) $input->getArgument('snapshot'));
        $name = ltrim($snapshot->paths[0] ?? '', '/');
        if ($name === '') {
            throw new \RuntimeException("Snapshot {$snapshot->shortId} has no recorded path");
        }
        $dir = $config->absolutePath((string) $input->getOption('to'));
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $target = "$dir/$name.gz";
        $reporter->notice('Downloading snapshot {short} ({name}, {time}) to {target}', ['short' => $snapshot->shortId, 'name' => $name, 'time' => $snapshot->time->format(DATE_ATOM), 'target' => $target]);
        $bytes = $restic->dumpToGzip($repo, $snapshot->id, $snapshot->paths[0], $target);
        if ($snapshot->totalBytesProcessed !== null && $bytes !== $snapshot->totalBytesProcessed) {
            @unlink($target);
            throw new \RuntimeException(sprintf('Downloaded %d bytes but the snapshot recorded %d; file removed', $bytes, $snapshot->totalBytesProcessed));
        }
        $reporter->notice('Downloaded {bytes} bytes (uncompressed) into {target}', ['bytes' => $bytes, 'target' => $target]);
        $output->writeln($target);
        return 0;
    }
}
