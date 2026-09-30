# offsite-backups

Daily offsite backups of a Drupal site's database and files into two restic
repositories on S3-compatible storage, packaged as a Composer package with a
Symfony Console CLI. Configuration lives in the consuming site's
`offsite-backup.yml`; secrets in its environment. This repository holds only
the tool: no site names, endpoints, schedules or retention policies.

## Install

```bash
composer config repositories.offsite-backups vcs https://github.com/digitalist-se/offsite-backups
composer require digitalist-se/offsite-backups:^1.0
```

The package installs as a Drupal module directory
(`web/modules/contrib/offsite_backup`) because a report module ships with it;
the CLI at `vendor/bin/offsite-backup` works without enabling anything.

## Configure

`offsite-backup.yml` at the project root, next to `composer.json`. Required:
`project`, `environment`, `s3.host`, `s3.bucket` (`project` defaults to
`PLATFORM_PROJECT`, `environment` to `PLATFORM_BRANCH`, then
`PLATFORM_ENVIRONMENT`). Everything else has a default; run
`vendor/bin/offsite-backup config:check` to see every effective value and
whether it came from the default, the file or the environment.

```yaml
project: site
s3:
  host: https://s3.example.com
  bucket: site-backups
files:
  paths: [web/sites/default/files]
retention: {daily: 7, biweekly: 1, monthly: 12}
```

Secrets are environment variables only, and the loader refuses a file that
contains one: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `RESTIC_PASSWORD`,
and optionally `OFFSITE_BACKUP_SLACK_WEBHOOK_URL`.

Every setting has an environment override (`config:check` lists the names).
An empty environment value counts as unset, so an empty list can only be
expressed in the file (for example `database: {structure_tables: []}`).

| Key | Meaning |
| --- | --- |
| `environment_types` | `PLATFORM_ENVIRONMENT_TYPE` values on which `db:backup`, `files:backup`, `prune` and `check` run; elsewhere they print `skipped` and exit 0 |
| `repositories.database`, `repositories.files` | restic repository paths inside the bucket |
| `restic.host`, `restic.bin`, `restic.cache_dir` | snapshot host name (defaults to `{project}-{environment}`), binary, cache directory |
| `database.dump_command` | dump template; `{file}` has no `.gz`, the command must write `{file}.gz` (Drush does with `--gzip`) |
| `database.structure_tables`, `database.min_bytes` | tables dumped as schema only; smallest acceptable dump |
| `drush.bin`, `drush.root`, `local_dir` | Drush binary and root; writable directory for the dump, locks and cache |
| `files.paths`, `files.excludes` | directories to snapshot and exclude patterns |
| `retention.daily`, `retention.biweekly`, `retention.monthly` | snapshots kept per class (see below) |
| `check_subset`, `status_max_age` | data subset read by `check`; staleness threshold for `status` |
| `log.channel`, `log.drupal` | watchdog channel; set `false` to skip the Drupal hand-over |
| `slack.channel` | Slack channel for failure messages; unset uses the webhook's default |

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
| `status [--max-age=] [--json]` | ages of the newest snapshots; exit 2 when stale |
| `config:check` | settings with sources, binaries, repository access |
| `tools:install --dir=DIR` | install the pinned restic (SHA-256 verified) |

Global options: `--force` (ignore the environment gate), `--config=PATH`,
`--env-stdin` (JSON object of environment overrides on stdin, for scripts
that must not put secrets in arguments).

Exit codes: 0 success or skipped, 1 failure, 2 stale.

Snapshots are tagged `daily`, `biweekly` (day 15) or `monthly` (day 01) from
the date, and carry `--host {project}-{environment}`. Retention keeps the
most recent N per class and site, as configured in `retention.*`.

## Scheduling

Run the four writing commands from your platform's cron. They decide the
environment gate themselves, so no shell guard is needed:

```yaml
crons:
  offsite-backup-database:
    spec: "<cron expression>"
    cmd: vendor/bin/offsite-backup db:backup
  offsite-backup-files:
    spec: "<cron expression>"
    cmd: vendor/bin/offsite-backup files:backup
  offsite-backup-prune:
    spec: "<cron expression>"
    cmd: vendor/bin/offsite-backup prune
  offsite-backup-check:
    spec: "<cron expression>"
    cmd: vendor/bin/offsite-backup check
```

Build hook, after `composer install`, to get the pinned restic binary:

```bash
vendor/bin/offsite-backup tools:install --dir=<directory on PATH or restic.bin>
```

## Logging and alerts

Each run logs to stdout, to the Drupal log through one `drush php:eval` per
flush (start, on error, end; JSON on stdin), and to Slack on failure when the
webhook is set. The Drupal success lines are stable so log-absence alerts can
match them:

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

Or with plain restic: `restic -r s3:https://<host>/<bucket>/<repository> snapshots`,
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
real bucket to run the suite against real storage. See `docs/manual-test.md`
for a hands-on walkthrough. Releases are git tags; consumers require `^1.0`.

## License

GPL-3.0-or-later. See `LICENSE`.
