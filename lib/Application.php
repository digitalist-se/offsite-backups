<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{
    public const VERSION = '1.0.0';

    public function __construct(private readonly string $projectRoot, private readonly Environment $env)
    {
        parent::__construct('offsite-backup', self::VERSION);
        $this->addCommands([
            new Command\InitCommand(),
            new Command\ConfigCheckCommand(),
            new Command\DbBackupCommand(),
            new Command\FilesBackupCommand(),
            new Command\PruneCommand(),
            new Command\CheckCommand(),
        ]);
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function getEnvironment(): Environment
    {
        return $this->env;
    }

    /** The Composer root package directory: identical for the CLI and for Drupal. */
    public static function detectProjectRoot(): string
    {
        if (class_exists(InstalledVersions::class)) {
            $root = InstalledVersions::getRootPackage()['install_path'] ?? null;
            if (is_string($root) && is_dir($root)) {
                return rtrim((string) realpath($root), '/');
            }
        }
        return rtrim((string) getcwd(), '/');
    }
}
