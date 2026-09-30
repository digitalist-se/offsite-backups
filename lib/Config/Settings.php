<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Config;

/**
 * The settings table. Keys are the offsite-backup.yml keys (dot notation).
 * Type 'secret' means environment only; the loader rejects it in the file.
 * database.dump_command: {file} is the dump path WITHOUT ".gz" (Drush appends
 * it with --gzip); a custom command must write {file}.gz.
 */
final class Settings
{
    public const DRUPAL_EXCLUDES = ['*/css', '*/js', '*/php', '*/styles', '*/tmp', '*/logs', '*/translations', '*/config_*'];

    public const SECRET_KEYS = ['aws_access_key_id', 'aws_secret_access_key', 'restic_password', 'slack.webhook_url'];

    /** @return array<string, array{env: string, default: mixed, type: string, required: bool}> */
    public static function all(): array
    {
        return [
            'project' => ['env' => 'OFFSITE_BACKUP_PROJECT', 'default' => null, 'type' => 'string', 'required' => true],
            'environment' => ['env' => 'OFFSITE_BACKUP_ENVIRONMENT', 'default' => null, 'type' => 'string', 'required' => true],
            'environment_types' => ['env' => 'OFFSITE_BACKUP_ENVIRONMENT_TYPES', 'default' => ['production'], 'type' => 'list', 'required' => false],
            's3.host' => ['env' => 'AWS_HOST', 'default' => null, 'type' => 'string', 'required' => true],
            's3.bucket' => ['env' => 'AWS_BUCKET', 'default' => null, 'type' => 'string', 'required' => true],
            'aws_access_key_id' => ['env' => 'AWS_ACCESS_KEY_ID', 'default' => null, 'type' => 'secret', 'required' => true],
            'aws_secret_access_key' => ['env' => 'AWS_SECRET_ACCESS_KEY', 'default' => null, 'type' => 'secret', 'required' => true],
            'restic_password' => ['env' => 'RESTIC_PASSWORD', 'default' => null, 'type' => 'secret', 'required' => true],
            'repositories.database' => ['env' => 'OFFSITE_BACKUP_DB_REPO', 'default' => 'database', 'type' => 'string', 'required' => false],
            'repositories.files' => ['env' => 'OFFSITE_BACKUP_FILES_REPO', 'default' => 'public-files', 'type' => 'string', 'required' => false],
            'restic.host' => ['env' => 'OFFSITE_BACKUP_RESTIC_HOST', 'default' => '{project}-{environment}', 'type' => 'string', 'required' => false],
            'restic.bin' => ['env' => 'OFFSITE_BACKUP_RESTIC_BIN', 'default' => 'restic', 'type' => 'string', 'required' => false],
            'restic.cache_dir' => ['env' => 'OFFSITE_BACKUP_RESTIC_CACHE_DIR', 'default' => '{local_dir}/restic-cache', 'type' => 'string', 'required' => false],
            'database.dump_command' => ['env' => 'OFFSITE_BACKUP_DB_DUMP_COMMAND', 'default' => '{drush} sql:dump --gzip --result-file={file} --structure-tables-list={structure_tables}', 'type' => 'string', 'required' => false],
            'database.structure_tables' => ['env' => 'OFFSITE_BACKUP_DB_STRUCTURE_TABLES', 'default' => ['cache', 'cache_*'], 'type' => 'list', 'required' => false],
            'database.min_bytes' => ['env' => 'OFFSITE_BACKUP_DB_MIN_BYTES', 'default' => 1048576, 'type' => 'int', 'required' => false],
            'drush.bin' => ['env' => 'OFFSITE_BACKUP_DRUSH_BIN', 'default' => 'vendor/bin/drush', 'type' => 'string', 'required' => false],
            'drush.root' => ['env' => 'OFFSITE_BACKUP_DRUPAL_ROOT', 'default' => 'web', 'type' => 'string', 'required' => false],
            'local_dir' => ['env' => 'OFFSITE_BACKUP_LOCAL_DIR', 'default' => 'backups', 'type' => 'string', 'required' => false],
            'files.paths' => ['env' => 'OFFSITE_BACKUP_FILES_PATHS', 'default' => ['web/sites/default/files'], 'type' => 'list', 'required' => false],
            'files.excludes' => ['env' => 'OFFSITE_BACKUP_FILES_EXCLUDES', 'default' => self::DRUPAL_EXCLUDES, 'type' => 'list', 'required' => false],
            'retention.daily' => ['env' => 'OFFSITE_BACKUP_KEEP_DAILY', 'default' => 7, 'type' => 'int', 'required' => false],
            'retention.biweekly' => ['env' => 'OFFSITE_BACKUP_KEEP_BIWEEKLY', 'default' => 1, 'type' => 'int', 'required' => false],
            'retention.monthly' => ['env' => 'OFFSITE_BACKUP_KEEP_MONTHLY', 'default' => 12, 'type' => 'int', 'required' => false],
            'check_subset' => ['env' => 'OFFSITE_BACKUP_CHECK_SUBSET', 'default' => '5%', 'type' => 'string', 'required' => false],
            'status_max_age' => ['env' => 'OFFSITE_BACKUP_STATUS_MAX_AGE', 'default' => '26h', 'type' => 'string', 'required' => false],
            'log.channel' => ['env' => 'OFFSITE_BACKUP_LOG_CHANNEL', 'default' => 'offsite-backup', 'type' => 'string', 'required' => false],
            'log.drupal' => ['env' => 'OFFSITE_BACKUP_LOG_DRUPAL', 'default' => true, 'type' => 'bool', 'required' => false],
            'slack.webhook_url' => ['env' => 'OFFSITE_BACKUP_SLACK_WEBHOOK_URL', 'default' => null, 'type' => 'secret', 'required' => false],
            'slack.channel' => ['env' => 'OFFSITE_BACKUP_SLACK_CHANNEL', 'default' => null, 'type' => 'string', 'required' => false],
        ];
    }
}
