# offsite-backups

Daily offsite backups of a Drupal site's database and files into two restic
repositories on S3-compatible storage. One Composer package for every
Digitalist site; configuration in `offsite-backup.yml`, secrets in the
environment.

## Install

```bash
composer config repositories.offsite-backups vcs https://github.com/digitalist-se/offsite-backups
composer require digitalist-se/offsite-backups:^1.0
```

The repository is private. Composer needs a GitHub token with read access:
`COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'` in the build
environment, or an `auth.json` next to `composer.json` (gitignored).

The package installs as a Drupal module directory (`web/modules/contrib/offsite_backup`)
because a report module ships with it; the CLI at `vendor/bin/offsite-backup`
works without enabling anything.

## Configure

`offsite-backup.yml` at the project root, next to `composer.json`. Everything
has a default except `project`, `environment`, `s3.host` and `s3.bucket`;
`project` and `environment` default to `PLATFORM_PROJECT` and
`PLATFORM_ENVIRONMENT`.

```yaml
project: site
s3:
  host: https://backups.example.com
  bucket: site
files:
  paths: [web/sites/default/files, private]
restic:
  bin: /app/.global/bin/restic
```

Secrets are environment variables only, and the loader refuses a file that
contains one: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `RESTIC_PASSWORD`,
and optionally `OFFSITE_BACKUP_SLACK_WEBHOOK_URL`.

Every setting has an environment override; run `vendor/bin/offsite-backup config:check`
to see the full table with each value's source (`default`, `file`, `env`).
An empty environment value counts as unset, so an empty list can only be
expressed in the file (for example `database: {structure_tables: []}`).

| Key | Default |
| --- | --- |
| `environment_types` | `[production]`: where `db:backup`, `files:backup`, `prune`, `check` run; elsewhere they print `skipped` |
| `repositories.database` / `repositories.files` | `database` / `public-files` |
| `restic.host` | `{project}-{environment}` |
| `restic.bin`, `restic.cache_dir` | `restic`, `{local_dir}/restic-cache` |
| `database.dump_command` | `{drush} sql:dump --gzip --result-file={file} --structure-tables-list={structure_tables}` |
| `database.structure_tables` | `[cache, cache_*]` |
| `database.min_bytes` | `1048576` |
| `drush.bin`, `drush.root` | `vendor/bin/drush`, `web` |
| `local_dir` | `backups` |
| `files.paths`, `files.excludes` | `[web/sites/default/files]`, the Drupal set |
| `retention.daily` / `biweekly` / `monthly` | `7` / `1` / `12` |
| `check_subset` | `5%` |
| `status_max_age` | `26h` |
| `log.channel`, `log.drupal` | `offsite-backup`, `true` |
| `slack.channel` | `#alerts` |

## Commands

| Command | What it does |
| --- | --- |
| `db:backup` | `drush sql:dump --gzip`, check the archive (gzip, size, tables, trailer), stream it uncompressed into restic, verify the snapshot |
| `files:backup` | restic snapshot of the files paths with the excludes |
| `prune [db\|files\|all] [--dry-run]` | `forget --keep-last N` per class and store, then `prune` |
| `check [db\|files\|all]` | `restic check --read-data-subset` |
| `init [db\|files\|all]` | create missing repositories (the bucket must exist) |
| `db:list [--json]` | dumps newest first |
| `db:download <latest\|id> --to=DIR` | one dump as `.sql.gz` |
| `files:restore --target=DIR [--snapshot=] [--path=]` | restore one snapshot path, flattened |
| `status [--max-age=26h] [--json]` | ages of the newest snapshots; exit 2 when stale |
| `config:check` | settings with sources, binaries, repository access |
| `tools:install --dir=DIR` | install the pinned restic (SHA-256 verified) |

Global options: `--force` (ignore the environment gate), `--config=PATH`,
`--env-stdin` (JSON object of environment overrides on stdin, for scripts
that must not put secrets in arguments).

Exit codes: 0 success or skipped, 1 failure, 2 stale.

Snapshots are tagged `daily`, `biweekly` (day 15) or `monthly` (day 01) from
the date, and carry `--host {project}-{environment}`. Retention keeps the
most recent N per class and site.

## Crons (Upsun / Platform.sh)

```yaml
crons:
  offsite-backup-database:
    spec: "0 1 * * *"
    cmd: vendor/bin/offsite-backup db:backup
  offsite-backup-files:
    spec: "0 2 * * *"
    cmd: vendor/bin/offsite-backup files:backup
  offsite-backup-prune:
    spec: "0 3 * * 0"
    cmd: vendor/bin/offsite-backup prune
  offsite-backup-check:
    spec: "0 5 * * 0"
    cmd: vendor/bin/offsite-backup check
```

Build hook, after `composer install`:

```bash
vendor/bin/offsite-backup tools:install --dir=/app/.global/bin
```

## Logging and alerts

Each run logs to stdout, to the Drupal log through one `drush php:eval` per
flush (start, on error, end; JSON on stdin), and to Slack on failure when the
webhook is set. The Drupal success lines are stable so Graylog absence alerts
can match them:

- `Backup process finished successfully: <name> (snapshot <id>)`
- `Files backup completed successfully (snapshot <id>)`
- `Retention finished successfully: db forgotten=N, files forgotten=M`
- `Repository check completed successfully: db, files`

The last run of each job is stored in Drupal State under
`offsite_backup.run.<job>` for the report module.

## Restore

```bash
export RESTIC_PASSWORD=... AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=...
vendor/bin/offsite-backup db:list
vendor/bin/offsite-backup db:download latest --to=sql          # writes sql/<date>-<project>-<env>.sql.gz
vendor/bin/offsite-backup files:restore --target=web/sites/default/files
```

Or with plain restic: `restic -r s3:https://<host>/<bucket>/database snapshots`,
`restic -r … dump latest /<name>.sql | gzip > dump.sql.gz`.

## Development

```bash
ddev start && ddev composer install
ddev exec bin/offsite-backup tools:install --dir=/tmp/tools
ddev exec vendor/bin/phpunit            # unit + integration (S3 container from .ddev)
ddev exec vendor/bin/phpstan analyse
```

Integration tests need `OFFSITE_BACKUP_TEST_S3_HOST` (set by `.ddev/config.yaml`
to the versitygw container) and skip otherwise. Point the same variables at a
real bucket to run the suite against production storage. Releases are git
tags (`v1.0.0`); consumers require `^1.0`.
