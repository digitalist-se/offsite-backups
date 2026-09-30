<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Config;

final class Config
{
    public const STORE_DB = 'db';
    public const STORE_FILES = 'files';

    /**
     * @param list<string> $environmentTypes
     * @param list<string> $dbStructureTables
     * @param list<string> $filesPaths
     * @param list<string> $filesExcludes
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $project,
        public readonly string $environment,
        public readonly array $environmentTypes,
        public readonly string $s3Host,
        public readonly string $s3Bucket,
        public readonly string $awsAccessKeyId,
        public readonly string $awsSecretAccessKey,
        public readonly string $resticPassword,
        public readonly string $dbRepo,
        public readonly string $filesRepo,
        public readonly string $resticHostSetting,
        public readonly string $resticBin,
        public readonly string $resticCacheDir,
        public readonly string $dbDumpCommand,
        public readonly array $dbStructureTables,
        public readonly int $dbMinBytes,
        public readonly string $drushBin,
        public readonly string $drushRoot,
        public readonly string $localDir,
        public readonly array $filesPaths,
        public readonly array $filesExcludes,
        public readonly int $keepDaily,
        public readonly int $keepBiweekly,
        public readonly int $keepMonthly,
        public readonly string $checkSubset,
        public readonly string $statusMaxAge,
        public readonly string $logChannel,
        public readonly bool $logDrupal,
        public readonly ?string $slackWebhookUrl,
        public readonly ?string $slackChannel,
    ) {}

    public static function fromResolved(Resolved $r): self
    {
        $v = $r->values;
        return new self(
            $r->projectRoot,
            (string) $v['project'], (string) $v['environment'], $v['environment_types'],
            (string) $v['s3.host'], (string) $v['s3.bucket'],
            (string) $v['aws_access_key_id'], (string) $v['aws_secret_access_key'], (string) $v['restic_password'],
            (string) $v['repositories.database'], (string) $v['repositories.files'],
            (string) $v['restic.host'], (string) $v['restic.bin'], (string) $v['restic.cache_dir'],
            (string) $v['database.dump_command'], $v['database.structure_tables'], (int) $v['database.min_bytes'],
            (string) $v['drush.bin'], (string) $v['drush.root'], (string) $v['local_dir'],
            $v['files.paths'], $v['files.excludes'],
            (int) $v['retention.daily'], (int) $v['retention.biweekly'], (int) $v['retention.monthly'],
            (string) $v['check_subset'], (string) $v['status_max_age'],
            (string) $v['log.channel'], (bool) $v['log.drupal'],
            $v['slack.webhook_url'] === null ? null : (string) $v['slack.webhook_url'], $v['slack.channel'] === null ? null : (string) $v['slack.channel'],
        );
    }

    public function repositoryUrl(string $store): string
    {
        $path = match ($store) {
            self::STORE_DB => $this->dbRepo,
            self::STORE_FILES => $this->filesRepo,
            default => throw new \InvalidArgumentException("Unknown store '$store'"),
        };
        return sprintf('s3:%s/%s/%s', $this->s3Host, $this->s3Bucket, $path);
    }

    public function resticHost(): string
    {
        return $this->resticHostSetting;
    }

    public function isEnabledEnvironmentType(?string $type): bool
    {
        return $type !== null && in_array($type, $this->environmentTypes, true);
    }

    /** @return array<string,int> class => keep count */
    public function keepCounts(): array
    {
        return ['daily' => $this->keepDaily, 'biweekly' => $this->keepBiweekly, 'monthly' => $this->keepMonthly];
    }

    public function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectRoot . '/' . $path;
    }
}
