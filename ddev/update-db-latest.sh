#!/usr/bin/env bash
# Host-side ddev command: pick a database dump from the offsite repository and
# make it the local <sql-dir>/<YYYYMMDD>-<label>.sql.gz, linked from
# <sql-dir>/db_latest.sql.gz. Credentials come from a Bitwarden item; the
# site's shim in .ddev/commands/host passes the options for that site.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

usage() {
  cat <<'USAGE'
Usage: update-db-latest.sh --bw-item=NAME [options]
  --bw-item=NAME       Bitwarden item with AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, RESTIC_PASSWORD
  --environment=NAME   site environment whose dumps to use (default: main)
  --sql-dir=DIR        where dumps live (default: sql)      --label=NAME  local file label (default: prod)
  --date=YYYY-MM-DD    pick that day's dump                  --latest      pick the newest without asking
  --list               print the available dumps and exit    --all         list everything, not only the newest 20
  --interactive        prompt even when stdin is not a terminal
  --keep-local=N       delete local dumps beyond the newest N (never the one just linked)
  --refresh            run `ddev refresh` afterwards
  --bin=PATH --restic-bin=PATH --exec=CMD --root=DIR   plumbing (defaults: vendor/bin/offsite-backup, restic, "ddev exec", $DDEV_APPROOT)
USAGE
}

BW_ITEM=""; ENVIRONMENT="main"; SQL_DIR="sql"; LABEL="prod"; OB_BIN="vendor/bin/offsite-backup"; RESTIC_BIN="restic"
OB_EXEC="${OB_EXEC-ddev exec}"; OB_ROOT="${DDEV_APPROOT:-$PWD}"
DATE=""; LATEST=0; ALL=0; LIST=0; INTERACTIVE=0; KEEP_LOCAL=""; REFRESH=0
for arg in "$@"; do
  case "$arg" in
    --bw-item=*) BW_ITEM="${arg#*=}" ;;      --environment=*) ENVIRONMENT="${arg#*=}" ;;
    --sql-dir=*) SQL_DIR="${arg#*=}" ;;      --label=*) LABEL="${arg#*=}" ;;
    --bin=*) OB_BIN="${arg#*=}" ;;           --restic-bin=*) RESTIC_BIN="${arg#*=}" ;;
    --exec=*) OB_EXEC="${arg#*=}" ;;         --root=*) OB_ROOT="${arg#*=}" ;;
    --date=*) DATE="${arg#*=}" ;;            --latest) LATEST=1 ;;
    --all) ALL=1 ;;                          --list) LIST=1 ;;
    --interactive) INTERACTIVE=1 ;;          --keep-local=*) KEEP_LOCAL="${arg#*=}" ;;
    --refresh) REFRESH=1 ;;                  -h|--help) usage; exit 0 ;;
    *) ob_die "Unknown argument: $arg (see --help)" ;;
  esac
done
export OB_BIN OB_EXEC OB_ROOT
[ -n "$BW_ITEM" ] || ob_die "--bw-item is required"
[ -z "$DATE" ] || [[ "$DATE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || ob_die "--date must be YYYY-MM-DD"
[ -z "$KEEP_LOCAL" ] || [[ "$KEEP_LOCAL" =~ ^[0-9]+$ ]] || ob_die "--keep-local must be a whole number"
ob_require_tools bw jq
cd "$OB_ROOT" && mkdir -p "$SQL_DIR"

ENV_JSON=$(ob_env_json "$BW_ITEM" "OFFSITE_BACKUP_ENVIRONMENT=$ENVIRONMENT" "OFFSITE_BACKUP_RESTIC_BIN=$RESTIC_BIN")
LISTING=$(ob_run "$ENV_JSON" db:list --json) || ob_die "Could not list the dumps"
COUNT=$(printf '%s' "$LISTING" | jq 'length')
[ "$COUNT" -gt 0 ] || ob_die "No dumps in the repository for this site"
mapfile -t IDS < <(printf '%s' "$LISTING" | jq -r '.[].id')
mapfile -t DATES < <(printf '%s' "$LISTING" | jq -r '.[].time[0:10]')
mapfile -t CLASSES < <(printf '%s' "$LISTING" | jq -r '.[].class')
mapfile -t NAMES < <(printf '%s' "$LISTING" | jq -r '.[].name')
mapfile -t SIZES < <(printf '%s' "$LISTING" | jq -r '.[].bytes // 0')

local_file_for() { printf '%s/%s-%s.sql.gz' "$SQL_DIR" "${1//-/}" "$LABEL"; }
human() { numfmt --to=iec --suffix=B "$1" 2>/dev/null || printf '%s B' "$1"; }
print_list() {
  local n=$COUNT i mark
  { [ "$ALL" = 1 ] || [ "$n" -le 20 ]; } || n=20
  for ((i = 0; i < n; i++)); do
    mark=""; [ -f "$(local_file_for "${DATES[$i]}")" ] && mark="  (cached locally)"
    printf '%3d) %s  %-8s %-9s %s%s\n' $((i + 1)) "${DATES[$i]}" "${CLASSES[$i]}" "$(human "${SIZES[$i]}")" "${NAMES[$i]}" "$mark"
  done
  [ "$n" -eq "$COUNT" ] || echo "     … $((COUNT - n)) more (use --all)"
}
if [ "$LIST" = 1 ]; then print_list; exit 0; fi

pick=0
if [ -n "$DATE" ]; then
  pick=-1
  for ((i = 0; i < COUNT; i++)); do [ "${DATES[$i]}" = "$DATE" ] && { pick=$i; break; }; done
  if [ "$pick" -lt 0 ]; then echo "Error: No dump dated $DATE. Available:" >&2; print_list >&2; exit 1; fi
elif [ "$LATEST" = 1 ]; then
  pick=0
elif [ "$INTERACTIVE" = 1 ] || [ -t 0 ]; then
  print_list
  while :; do
    read -r -p "Select [1-$COUNT] (default: 1 = newest): " answer || answer=""
    answer="${answer:-1}"
    if [[ "$answer" =~ ^[0-9]+$ ]] && [ "$answer" -ge 1 ] && [ "$answer" -le "$COUNT" ]; then pick=$((answer - 1)); break; fi
    echo "Please enter a number between 1 and $COUNT."
  done
else
  echo "No terminal and no --date/--latest: using the newest dump (${DATES[0]})."
fi

ID="${IDS[$pick]}"; DAY="${DATES[$pick]}"; NAME="${NAMES[$pick]}"
TARGET="$(local_file_for "$DAY")"
if [ -f "$TARGET" ]; then
  echo "Using cached $TARGET"
else
  echo "Downloading $NAME (${CLASSES[$pick]}, $DAY)…"
  TMP="$SQL_DIR/.download"; mkdir -p "$TMP"
  ob_run "$ENV_JSON" db:download "$ID" --to="$(ob_container_path "$TMP")" >/dev/null || ob_die "Download failed"
  DOWNLOADED="$TMP/$NAME.gz"
  [ -f "$DOWNLOADED" ] || ob_die "Expected $DOWNLOADED after the download"
  mv "$DOWNLOADED" "$TARGET"; rmdir "$TMP" 2>/dev/null || true
fi
ln -sfn "$(basename "$TARGET")" "$SQL_DIR/db_latest.sql.gz"
echo "db_latest.sql.gz -> $(basename "$TARGET")"
zcat "$TARGET" | tail -n 2 | grep 'Dump completed on' || echo "(no 'Dump completed on' trailer found in the dump)"

if [ -n "$KEEP_LOCAL" ]; then
  mapfile -t LOCAL < <(ls -1t "$SQL_DIR"/*-"$LABEL".sql.gz 2>/dev/null)
  for ((i = KEEP_LOCAL; i < ${#LOCAL[@]}; i++)); do
    [ "$(basename "${LOCAL[$i]}")" = "$(basename "$TARGET")" ] && continue
    rm -f "${LOCAL[$i]}"; echo "Removed ${LOCAL[$i]}"
  done
fi
echo "Local dumps: $(ls -1 "$SQL_DIR"/*-"$LABEL".sql.gz 2>/dev/null | wc -l) file(s), $(du -ch "$SQL_DIR"/*-"$LABEL".sql.gz 2>/dev/null | tail -n 1 | cut -f1)"
if [ "$REFRESH" = 1 ]; then
  if [ -n "$OB_EXEC" ]; then ddev refresh; else echo "(--refresh needs ddev; skipped)"; fi
fi
