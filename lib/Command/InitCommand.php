<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InitCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('init')
            ->setDescription('Initialise the restic repositories that do not exist yet')
            ->addArgument('store', InputArgument::OPTIONAL, 'db, files or all', 'all');
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $restic = $this->restic($config);
        foreach (StoreArgument::parse($input->getArgument('store')) as $store) {
            $url = $config->repositoryUrl($store);
            if ($restic->repositoryExists($url)) {
                $reporter->notice('Repository {url} already initialised', ['url' => $url]);
                continue;
            }
            $restic->init($url);
            $reporter->notice('Initialised repository {url}', ['url' => $url]);
        }
        return 0;
    }
}
