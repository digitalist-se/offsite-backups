<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Command;

use Digitalist\OffsiteBackup\Application;
use Digitalist\OffsiteBackup\Backup\LockException;
use Digitalist\OffsiteBackup\Backup\RunLock;
use Digitalist\OffsiteBackup\Config\Config;
use Digitalist\OffsiteBackup\Config\ConfigException;
use Digitalist\OffsiteBackup\Config\ConfigLoader;
use Digitalist\OffsiteBackup\Environment;
use Digitalist\OffsiteBackup\Process\ProcessRunner;
use Digitalist\OffsiteBackup\Report\DrupalSink;
use Digitalist\OffsiteBackup\Report\Reporter;
use Digitalist\OffsiteBackup\Report\RunReport;
use Digitalist\OffsiteBackup\Report\Sink;
use Digitalist\OffsiteBackup\Report\SlackSink;
use Digitalist\OffsiteBackup\Restic\Restic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    private ?ProcessRunner $runner = null;

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run even when the environment type is not enabled');
        $this->addOption('env-stdin', null, InputOption::VALUE_NONE, 'Read a JSON object of environment overrides from stdin');
        $this->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to offsite-backup.yml (default: <project root>/offsite-backup.yml)');
        $this->configureCommand();
    }

    abstract protected function configureCommand(): void;

    abstract protected function runCommand(Config $config, Reporter $reporter, InputInterface $input, OutputInterface $output): int;

    /** Gated commands run only on enabled environment types, take a lock and report to the sinks. */
    protected function isGated(): bool
    {
        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $this->resolveEnvironment($input, $output);
        if ($env === null) {
            return 1;
        }
        $loader = $this->loader($env, $input);
        try {
            if ($this->isGated() && !$input->getOption('force')) {
                // Decide the gate before requiring secrets: a cron on a non-production
                // environment without variables must still exit 0 with "skipped".
                $type = $env->get('PLATFORM_ENVIRONMENT_TYPE');
                $enabled = $loader->resolve()->values['environment_types'];
                if ($type === null || !in_array($type, (array) $enabled, true)) {
                    $output->writeln(sprintf('skipped (environment type "%s")', $type ?? 'unset'));
                    return 0;
                }
            }
            $config = $loader->load();
        } catch (ConfigException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $report = new RunReport((string) $this->getName(), $config->project, $config->environment, $this->now());
        $reporter = new Reporter($report, $output, $this->isGated() ? $this->sinks($config, $output) : []);
        $lock = null;

        if ($this->isGated()) {
            try {
                $lock = RunLock::acquire($config->localDir, (string) $this->getName());
            } catch (LockException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return 1;
            }
        }

        $reporter->start();
        try {
            $code = $this->runCommand($config, $reporter, $input, $output);
            if ($code === 0) {
                $report->succeed();
            } else {
                $report->fail("exit code $code");
                $reporter->failure();
            }
            return $code;
        } catch (\Throwable $e) {
            $reporter->error($e->getMessage());
            $report->fail($e->getMessage());
            $reporter->failure();
            return 1;
        } finally {
            $reporter->finish();
            $lock?->release();
        }
    }

    protected function resolveEnvironment(InputInterface $input, OutputInterface $output): ?Environment
    {
        $env = $this->application()->getEnvironment();
        if (!$input->getOption('env-stdin')) {
            return $env;
        }
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $json = (string) stream_get_contents($stream ?? STDIN);
        try {
            return $env->with(self::readEnvFromStdin($json));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return null;
        }
    }

    /** @return array<string,string> */
    public static function readEnvFromStdin(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \InvalidArgumentException('--env-stdin expects a JSON object of environment variable names to string values');
        }
        $out = [];
        foreach ($data as $name => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException("--env-stdin expects a JSON object of scalar values; '$name' is not scalar");
            }
            $out[(string) $name] = $value === null ? '' : (string) $value;
        }
        return $out;
    }

    public static function stateKeyFor(string $commandName): string
    {
        return 'offsite_backup.run.' . str_replace([':', '-'], '_', $commandName);
    }

    protected function loader(Environment $env, InputInterface $input): ConfigLoader
    {
        $path = $input->getOption('config');
        return new ConfigLoader($this->application()->getProjectRoot(), $env, is_string($path) ? $path : null);
    }

    protected function application(): Application
    {
        $app = $this->getApplication();
        if (!$app instanceof Application) {
            throw new \LogicException('Command must run inside ' . Application::class);
        }
        return $app;
    }

    protected function runner(): ProcessRunner
    {
        return $this->runner ??= new ProcessRunner();
    }

    protected function restic(Config $config): Restic
    {
        return Restic::fromConfig($config, $this->runner());
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** @return list<Sink> */
    private function sinks(Config $config, OutputInterface $output): array
    {
        $sinks = [];
        if ($config->logDrupal) {
            $sinks[] = new DrupalSink($this->runner(), $config->absolutePath($config->drushBin), $config->absolutePath($config->drushRoot), $config->logChannel, self::stateKeyFor((string) $this->getName()), $output);
        }
        if ($config->slackWebhookUrl !== null) {
            $sinks[] = new SlackSink($config->slackWebhookUrl, $config->slackChannel, $output);
        }
        return $sinks;
    }
}
