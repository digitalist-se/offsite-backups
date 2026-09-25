<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DbListCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('db:list')
            ->setDescription('List the database dump snapshots, newest first')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $repo = $config->repositoryUrl(Config::STORE_DB);
        $restic = $this->restic($config);
        $restic->requireRepository($repo);
        $snapshots = $restic->snapshots($repo, ['host' => $config->resticHost()]);
        $rows = array_map(static fn ($s): array => [
            'id' => $s->id,
            'short_id' => $s->shortId,
            'time' => $s->time->format(DATE_ATOM),
            'class' => $s->tags[0] ?? '',
            'name' => ltrim($s->paths[0] ?? '', '/'),
            'bytes' => $s->totalBytesProcessed,
        ], $snapshots);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }
        if ($rows === []) {
            $output->writeln(sprintf('No database snapshots for host %s in %s', $config->resticHost(), $repo));
            return 0;
        }
        $table = new Table($output);
        $table->setHeaders(['date', 'class', 'name', 'snapshot', 'size']);
        foreach ($rows as $row) {
            $table->addRow([substr($row['time'], 0, 16), $row['class'], $row['name'], $row['short_id'], self::bytes($row['bytes'])]);
        }
        $table->render();
        return 0;
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '?';
        }
        $value = (float) $bytes;
        foreach (['B', 'KiB', 'MiB', 'GiB'] as $unit) {
            if ($value < 1024 || $unit === 'GiB') {
                return sprintf($unit === 'B' ? '%d %s' : '%.1f %s', $value, $unit);
            }
            $value /= 1024;
        }
        return (string) $bytes;
    }
}
