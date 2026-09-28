#!/usr/bin/env bash
# Stands in for `drush sql:dump --gzip` in the package tests: writes a real
# gzipped SQL dump with mysqldump's trailer. Like Drush with --gzip, it
# appends ".gz" to the --result-file path itself. Set FAKE_DUMP_TRUNCATE=1 to
# omit the trailer (an interrupted mysqldump).
set -euo pipefail
file=""
for arg in "$@"; do
  case "$arg" in --result-file=*) file="${arg#--result-file=}";; esac
done
[ -n "$file" ] || { echo "fake-dump: missing --result-file" >&2; exit 2; }
{
  echo "-- MariaDB dump (fake)"
  for i in $(seq 1 40); do
    echo "CREATE TABLE \`t$i\` (id int);"
    echo "INSERT INTO \`t$i\` VALUES ($i);"
  done
  for i in $(seq 1 3000); do echo "-- padding line $i"; done
  if [ -z "${FAKE_DUMP_TRUNCATE:-}" ]; then
    echo "-- Dump completed on 2026-09-25  1:00:12"
  fi
} | gzip -6 > "$file.gz"
