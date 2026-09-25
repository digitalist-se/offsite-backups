# Manual test drive

Three levels, from no credentials at all to the real site. Every command
prints what it does; exit code 0 means success, 1 failure, 2 stale (`status`).

## 1. Sandbox in this repository (no credentials, no Drupal)

The ddev project ships an S3-compatible container (versitygw). A throwaway
"project" under `/tmp/demo` stands in for a site; the database dump is
produced by `tests/Support/fake-dump.sh` (a real gzip with mysqldump's
trailer) and the Drupal hand-over goes to `tests/Support/fake-drush.php`,
which records what Drupal would have received.

```bash
ddev start && ddev composer install
ddev exec bin/offsite-backup tools:install --dir=/tmp/tools    # pinned restic, SHA-256 verified
ddev ssh
```

Inside the container:

```bash
mkdir -p /tmp/demo/files/css /tmp/demo/private && cd /tmp/demo
echo hello > files/hello.txt; echo agg > files/css/agg.css; echo secret > private/secret.pdf
cat > offsite-backup.yml <<'YML'
project: demo
environment: local
s3: {host: http://s3:7070, bucket: demo}
local_dir: /tmp/demo/backups
files: {paths: [/tmp/demo/files, /tmp/demo/private]}
restic: {bin: /tmp/tools/restic}
drush: {bin: /var/www/html/tests/Support/fake-drush.php, root: /tmp/demo}
database: {dump_command: "/var/www/html/tests/Support/fake-dump.sh --result-file={file}", min_bytes: 100}
YML
export AWS_ACCESS_KEY_ID=testkey AWS_SECRET_ACCESS_KEY=testsecret12345 RESTIC_PASSWORD=demo-password
export PLATFORM_ENVIRONMENT_TYPE=production FAKE_DRUSH_OUT=/tmp/demo/drush.log
export OFFSITE_BACKUP_CONFIG=/tmp/demo/offsite-backup.yml
OB=/var/www/html/bin/offsite-backup
```

`OFFSITE_BACKUP_CONFIG` is needed here only because the project root is
detected from Composer, which in this repository is the package itself; a
site never needs it.

Then, in this order, and what to look for:

| Command | Expect |
| --- | --- |
| `$OB init` | `Initialised repository …/demo/database` and `…/demo/public-files`; the bucket is created too. Run it again: `already initialised`. |
| `$OB config:check` | the settings table with a `source` column, then `[ok]` for restic, both repositories, drush, mysqldump, gzip, bash, the local directory, and `gated commands would run`. |
| `$OB db:backup` | dump, check (`40 tables`), upload, `Verification succeeded`, `Backup process finished successfully: … (snapshot …)`, `Removed local dump`. |
| `$OB files:backup` | `Files backup completed successfully (snapshot …)`. `css/agg.css` is excluded by default. |
| `$OB db:list` | one row, newest first, with class and size. `--json` for scripts. |
| `$OB status` | ages per store and class; `OK: newest snapshots are within 1d 2h`. Try `--max-age=1s` later: exit 2 and `STALE:` lines. |
| `$OB prune --dry-run` | `… would be forgotten` per class, `prune skipped`, and a `dry run finished` line that is deliberately different from the real success line. |
| `$OB prune` | `Retention finished successfully: db forgotten=0, files forgotten=0`. |
| `$OB check` | `Repository check completed successfully: db, files`. |
| `$OB db:download latest --to=/tmp/demo/sql` | the `.sql.gz` path on the last line; `zcat` it and see the trailer. |
| `$OB files:restore --target=/tmp/demo/restored` | `hello.txt` restored, no `css/`. |
| `PLATFORM_ENVIRONMENT_TYPE=development $OB db:backup` | `skipped (environment type "development")`, exit 0, nothing logged to Drupal. Add `--force` to run anyway. |
| `FAKE_DUMP_TRUNCATE=1 $OB db:backup` | exit 1, `does not end with the '-- Dump completed on' trailer`, `db:list` unchanged. |
| `tail -1 /tmp/demo/drush.log \| python3 -m json.tool` | the last hand-over: `log` lines plus `state` with the run report Drupal stores. |

Slack: set `OFFSITE_BACKUP_SLACK_WEBHOOK_URL` (and `OFFSITE_BACKUP_SLACK_CHANNEL`
to a test channel) before the truncated-dump run and one message arrives with
the error and the last log lines. Nothing is sent on success.

The same walkthrough is scripted in `.tools/demo.sh` when present; it is not
part of the package.

## 2. Same commands against the real bucket

Export the real values instead of the test ones and point `s3.host`/`s3.bucket`
at the the object storage bucket (it must exist; restic creates only the repositories):

```bash
export AWS_ACCESS_KEY_ID=… AWS_SECRET_ACCESS_KEY=… RESTIC_PASSWORD=…
```

Use a throwaway bucket or a dedicated `repositories.database`/`repositories.files`
name so the demo snapshots do not mix with a site's. The test suite can also
run against the real storage: set `OFFSITE_BACKUP_TEST_S3_HOST`,
`OFFSITE_BACKUP_TEST_S3_ACCESS_KEY`, `OFFSITE_BACKUP_TEST_S3_SECRET_KEY` and
run `vendor/bin/phpunit --testsuite integration`. It creates buckets named
`test-<hex>` that you delete by hand afterwards.

## 3. On a site (after `composer require`)

From the project root, with the site's `offsite-backup.yml` in place and the
secrets exported (or set as platform variables):

```bash
vendor/bin/offsite-backup config:check      # real drush, real mysqldump, real repositories
vendor/bin/offsite-backup init
vendor/bin/offsite-backup db:backup --force  # real `drush sql:dump`, real Drupal watchdog + State
vendor/bin/offsite-backup files:backup --force
drush watchdog:show --type=offsite-backup
drush state:get offsite_backup.run.db_backup --format=json
```

On Upsun, `--force` is only needed outside production.
