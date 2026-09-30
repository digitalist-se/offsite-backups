<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Config\ConfigException;
use Digitalist\OffsiteBackup\Config\Settings;
use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Restic\ResticException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ConfigCheckCommand extends BaseCommand
{
    protected function configureCommand(): void
    {
        $this->setName('config:check')
            ->setDescription('Show every setting with its source, and test binaries and repository access')
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'upsun or platform, for the variable hints (default: detected)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $this->resolveEnvironment($input, $output);
        if ($env === null) {
            return 1;
        }
        try {
            $resolved = $this->loader($env, $input)->resolve();
        } catch (ConfigException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
        $settings = Settings::all();
        $output->writeln('Configuration file: ' . ($resolved->configFile ?? '(none, environment only)'));
        $table = new Table($output);
        $table->setHeaders(['setting', 'value', 'source', 'env override']);
        foreach ($settings as $key => $def) {
            $value = $resolved->values[$key];
            $shown = match (true) {
                $value === null => $def['required'] ? '(missing)' : '(unset)',
                $def['type'] === 'secret' => '********',
                is_array($value) => implode(', ', $value),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
            $table->addRow([$key, $shown, $resolved->sources[$key], $def['env']]);
        }
        $table->render();

        $failed = false;
        if ($resolved->missing !== []) {
            $output->writeln('Missing: ' . implode(', ', array_map(static fn (string $k): string => "$k ({$settings[$k]['env']})", $resolved->missing)));
            $platform = $input->getOption('platform');
            $cli = is_string($platform) ? $platform : (is_file($resolved->projectRoot . '/.upsun/config.yaml') ? 'upsun' : 'platform');
            foreach ($resolved->missing as $key) {
                if ($settings[$key]['type'] === 'secret') {
                    $output->writeln(sprintf("  %s variable:create --level project --name env:%s --sensitive true --visible-build false --visible-runtime true --value '<value>'", $cli, $settings[$key]['env']));
                } else {
                    $output->writeln(sprintf('  set %s in offsite-backup.yml (or %s)', $key, $settings[$key]['env']));
                }
            }
            return 1;
        }

        $config = Config::fromResolved($resolved);
        $ok = static fn (bool $pass, string $label): string => sprintf('  [%s] %s', $pass ? 'ok' : 'FAIL', $label);
        $output->writeln('Checks:');

        $restic = $this->restic($config);
        try {
            $version = $restic->version();
            $output->writeln($ok(true, "restic binary {$config->resticBin} (version $version)"));
            foreach ([Config::STORE_DB, Config::STORE_FILES] as $store) {
                try {
                    $exists = $restic->repositoryExists($config->repositoryUrl($store));
                    $output->writeln($ok($exists, "repository $store: " . ($exists ? 'ok' : 'missing, run init') . ' (' . $config->repositoryUrl($store) . ')'));
                    $failed = $failed || !$exists;
                } catch (ResticException $e) {
                    $failed = true;
                    $output->writeln($ok(false, "repository $store: " . $e->getMessage()));
                }
            }
        } catch (ResticException $e) {
            $failed = true;
            $output->writeln($ok(false, "restic binary {$config->resticBin} not found or not working: " . $e->getMessage()));
        }

        $drush = $this->runner()->run([$config->absolutePath($config->drushBin), '--version'], [], null, 60);
        $output->writeln($ok($drush->ok(), 'drush ' . $config->absolutePath($config->drushBin) . ($drush->ok() ? '' : ' not found or failing: ' . $drush->tail(2))));
        $failed = $failed || !$drush->ok();

        foreach (['mysqldump or mariadb-dump' => 'command -v mysqldump || command -v mariadb-dump', 'gzip' => 'command -v gzip', 'bash' => 'command -v bash'] as $label => $probe) {
            $found = $this->runner()->run(['sh', '-c', $probe], [], null, 10)->ok();
            $output->writeln($ok($found, "$label on PATH"));
            $failed = $failed || !$found;
        }

        $writable = (is_dir($config->localDir) || @mkdir($config->localDir, 0700, true)) && is_writable($config->localDir);
        $output->writeln($ok($writable, "local directory {$config->localDir} writable"));
        $failed = $failed || !$writable;

        $type = $env->get('PLATFORM_ENVIRONMENT_TYPE');
        $output->writeln($config->isEnabledEnvironmentType($type)
            ? sprintf('  gated commands would run (environment type "%s")', $type)
            : sprintf('  gated commands would be skipped (environment type "%s", enabled: %s)', $type ?? 'unset', implode(', ', $config->environmentTypes)));

        return $failed ? 1 : 0;
    }

    protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int
    {
        return 0;
    }
}
