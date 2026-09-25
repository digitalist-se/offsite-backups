<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Digitalist\OffsiteBackup\Tools\ResticInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Needs no configuration: used in build hooks before any variable exists. */
final class ToolsInstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('tools:install')
            ->setDescription('Download the pinned restic release (SHA-256 verified) into a directory')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target directory')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Reinstall even if the right version is present');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $input->getOption('dir');
        if (!is_string($dir) || $dir === '') {
            $output->writeln('<error>--dir is required</error>');
            return 1;
        }
        try {
            (new ResticInstaller(new ProcessRunner()))->install($dir, (bool) $input->getOption('force'), static fn (string $m) => $output->writeln($m));
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
        return 0;
    }
}
