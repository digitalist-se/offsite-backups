<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Backup\BackupClass;
use Digitalist\OffsiteBackup\Backup\Duration;
use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Restic\Snapshot;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('status')
            ->setDescription('Show the newest snapshots and their ages; exit 2 when stale')
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Staleness threshold, e.g. 26h (default: status_max_age)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        $maxAgeOption = $input->getOption('max-age');
        $maxAge = Duration::parse(is_string($maxAgeOption) ? $maxAgeOption : $config->statusMaxAge);
        $now = $this->now();
        $restic = $this->restic($config);
        $host = $config->resticHost();
        $rows = [];
        $staleReasons = [];

        $dbRepo = $config->repositoryUrl(Config::STORE_DB);
        if ($restic->repositoryExists($dbRepo)) {
            $db = $restic->snapshots($dbRepo, ['host' => $host, 'latest' => 1])[0] ?? null;
            $rows[] = self::row('db', 'any', $db, $now, $maxAge, true, $staleReasons);
        } else {
            $staleReasons[] = 'db: repository does not exist, run `offsite-backup init`';
            $rows[] = self::row('db', 'any', null, $now, $maxAge, false, $staleReasons);
        }

        $filesRepo = $config->repositoryUrl(Config::STORE_FILES);
        $filesExists = $restic->repositoryExists($filesRepo);
        if ($filesExists) {
            // Freshness is judged on the newest snapshot of any class: on the 1st
            // and 15th the nightly snapshot is monthly or biweekly, not daily.
            $newest = $restic->snapshots($filesRepo, ['host' => $host, 'latest' => 1])[0] ?? null;
            $rows[] = self::row('files', 'any', $newest, $now, $maxAge, true, $staleReasons);
        } else {
            $staleReasons[] = 'files: repository does not exist, run `offsite-backup init`';
            $rows[] = self::row('files', 'any', null, $now, $maxAge, false, $staleReasons);
        }
        foreach (BackupClass::ALL as $class) {
            $snap = $filesExists ? ($restic->snapshots($filesRepo, ['host' => $host, 'tag' => $class, 'latest' => 1])[0] ?? null) : null;
            $rows[] = self::row('files', $class, $snap, $now, $maxAge, false, $staleReasons);
        }

        $stale = $staleReasons !== [];
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['host' => $host, 'max_age_seconds' => $maxAge, 'stale' => $stale, 'reasons' => $staleReasons, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $table = new Table($output);
            $table->setHeaders(['store', 'class', 'snapshot', 'time', 'age', 'name/path']);
            foreach ($rows as $r) {
                $table->addRow([$r['store'], $r['class'], $r['snapshot'] ?? '-', $r['time'] ?? '-', $r['age'] ?? '-', $r['path'] ?? '-']);
            }
            $table->render();
            foreach ($staleReasons as $reason) {
                $output->writeln("<error>STALE: $reason</error>");
            }
            if (!$stale) {
                $output->writeln(sprintf('OK: newest snapshots are within %s', Duration::human($maxAge)));
            }
        }
        return $stale ? 2 : 0;
    }

    /**
     * @param list<string> $staleReasons
     * @return array<string,mixed>
     */
    private static function row(string $store, string $class, ?Snapshot $snap, \DateTimeImmutable $now, int $maxAge, bool $checked, array &$staleReasons): array
    {
        if ($snap === null) {
            if ($checked) {
                $staleReasons[] = "$store: no snapshot" . ($class === 'any' ? '' : " tagged $class");
            }
            return ['store' => $store, 'class' => $class, 'snapshot' => null, 'time' => null, 'age_seconds' => null, 'age' => null, 'path' => null, 'stale' => $checked];
        }
        $age = $snap->ageSeconds($now);
        $isStale = $checked && $age > $maxAge;
        if ($isStale) {
            $staleReasons[] = sprintf('%s: newest %s snapshot %s is %s old (limit %s)', $store, $class, $snap->shortId, Duration::human($age), Duration::human($maxAge));
        }
        return ['store' => $store, 'class' => $class, 'snapshot' => $snap->shortId, 'time' => $snap->time->format(DATE_ATOM), 'age_seconds' => $age, 'age' => Duration::human($age), 'path' => $snap->paths[0] ?? null, 'stale' => $isStale];
    }
}
