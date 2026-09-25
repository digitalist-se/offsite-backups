<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Backup\BackupClass;
use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Restic\ResticException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PruneCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('prune')
            ->setDescription('Apply retention (keep-last per class) and prune the repositories')
            ->addArgument('store', InputArgument::OPTIONAL, 'db, files or all', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be forgotten; do not remove or prune');
    }

    protected function isGated(): bool
    {
        return true;
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $stores = StoreArgument::parse($input->getArgument('store'));
        $dryRun = (bool) $input->getOption('dry-run');
        $restic = $this->restic($config);
        $keep = $config->keepCounts();
        $forgotten = [Config::STORE_DB => null, Config::STORE_FILES => null];
        $failures = [];

        foreach ($stores as $store) {
            $repo = $config->repositoryUrl($store);
            $reporter->notice('Retention started for {store}: {repo} keep daily={d} biweekly={b} monthly={m} dry_run={dry}', ['store' => $store, 'repo' => $repo, 'd' => $keep['daily'], 'b' => $keep['biweekly'], 'm' => $keep['monthly'], 'dry' => $dryRun ? 'yes' : 'no']);
            try {
                $restic->requireRepository($repo);
                $forgotten[$store] = 0;
                foreach (BackupClass::ALL as $class) {
                    $n = $restic->forget($repo, $class, $config->resticHost(), $keep[$class], $dryRun);
                    $forgotten[$store] += $n;
                    $reporter->notice('{store}: {class} keep-last {keep}: {n} snapshot(s) {verb}', ['store' => $store, 'class' => $class, 'keep' => $keep[$class], 'n' => $n, 'verb' => $dryRun ? 'would be forgotten' : 'forgotten']);
                }
                if ($dryRun) {
                    $reporter->notice('Dry run: prune skipped for {repo}', ['repo' => $repo]);
                } else {
                    $restic->prune($repo);
                    $reporter->notice('Pruned {repo}', ['repo' => $repo]);
                }
            } catch (ResticException $e) {
                $failures[] = "$store: " . $e->getMessage();
                $reporter->error('Retention failed for {store}: {error}', ['store' => $store, 'error' => $e->getMessage()]);
            }
        }

        $reporter->report()->details = ['dry_run' => $dryRun, 'stores' => $stores, 'forgotten' => $forgotten];
        if ($failures !== []) {
            throw new \RuntimeException('Retention failed for ' . implode('; ', $failures));
        }
        $fmt = static fn (?int $n): string => $n === null ? '-' : (string) $n;
        if ($dryRun) {
            $reporter->notice('Retention dry run finished: db would forget={db}, files would forget={files}', ['db' => $fmt($forgotten['db']), 'files' => $fmt($forgotten['files'])]);
        } else {
            $reporter->notice('Retention finished successfully: db forgotten={db}, files forgotten={files}', ['db' => $fmt($forgotten['db']), 'files' => $fmt($forgotten['files'])]);
        }
        return 0;
    }
}
