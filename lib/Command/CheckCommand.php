<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Restic\ResticException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('check')
            ->setDescription('Run restic check with a data subset on the repositories')
            ->addArgument('store', InputArgument::OPTIONAL, 'db, files or all', 'all');
    }

    protected function isGated(): bool
    {
        return true;
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $stores = StoreArgument::parse($input->getArgument('store'));
        $restic = $this->restic($config);
        $failures = [];
        foreach ($stores as $store) {
            $repo = $config->repositoryUrl($store);
            $reporter->notice('Check started for {store}: {repo} (read-data-subset {subset})', ['store' => $store, 'repo' => $repo, 'subset' => $config->checkSubset]);
            try {
                $restic->requireRepository($repo);
                $restic->check($repo, $config->checkSubset);
                $reporter->notice('Check passed for {store}', ['store' => $store]);
            } catch (ResticException $e) {
                $failures[] = "$store: " . $e->getMessage();
                $reporter->error('Check failed for {store}: {error}', ['store' => $store, 'error' => $e->getMessage()]);
            }
        }
        $reporter->report()->details = ['stores' => $stores, 'subset' => $config->checkSubset];
        if ($failures !== []) {
            throw new \RuntimeException('Repository check failed for ' . implode('; ', $failures));
        }
        $reporter->notice('Repository check completed successfully: {stores}', ['stores' => implode(', ', $stores)]);
        return 0;
    }
}
