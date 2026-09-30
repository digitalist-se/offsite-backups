#!/usr/bin/env bash
# Host-side ddev command: restore one files snapshot from the offsite
# repository into a local directory (files are copied over; extra local files
# are kept). Credentials come from a Bitwarden item.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

usage() {
  cat <<'USAGE'
Usage: update-files-latest.sh --bw-item=NAME --target=DIR [options]
  --snapshot=ID|latest  which files snapshot (default: latest)
  --path=PATH           snapshot path to restore (default: the first recorded path)
  --environment=NAME    site environment (default: main)
  --bin=PATH --restic-bin=PATH --exec=CMD --root=DIR   plumbing (defaults: vendor/bin/offsite-backup, restic, "ddev exec", $DDEV_APPROOT)
USAGE
}

BW_ITEM=""; ENVIRONMENT="main"; TARGET=""; SNAPSHOT="latest"; SPATH=""; OB_BIN="vendor/bin/offsite-backup"; RESTIC_BIN="restic"
OB_EXEC="${OB_EXEC-ddev exec}"; OB_ROOT="${DDEV_APPROOT:-$PWD}"
for arg in "$@"; do
  case "$arg" in
    --bw-item=*) BW_ITEM="${arg#*=}" ;;   --environment=*) ENVIRONMENT="${arg#*=}" ;;
    --target=*) TARGET="${arg#*=}" ;;     --snapshot=*) SNAPSHOT="${arg#*=}" ;;
    --path=*) SPATH="${arg#*=}" ;;        --bin=*) OB_BIN="${arg#*=}" ;;
    --restic-bin=*) RESTIC_BIN="${arg#*=}" ;; --exec=*) OB_EXEC="${arg#*=}" ;;
    --root=*) OB_ROOT="${arg#*=}" ;;      -h|--help) usage; exit 0 ;;
    *) ob_die "Unknown argument: $arg (see --help)" ;;
  esac
done
export OB_BIN OB_EXEC OB_ROOT
[ -n "$BW_ITEM" ] || ob_die "--bw-item is required"
[ -n "$TARGET" ] || ob_die "--target is required"
ob_require_tools bw jq
cd "$OB_ROOT"

ENV_JSON=$(ob_env_json "$BW_ITEM" "OFFSITE_BACKUP_ENVIRONMENT=$ENVIRONMENT" "OFFSITE_BACKUP_RESTIC_BIN=$RESTIC_BIN")
ARGS=(files:restore "--snapshot=$SNAPSHOT" "--target=$(ob_container_path "$TARGET")")
[ -z "$SPATH" ] || ARGS+=("--path=$SPATH")
echo "Restoring files snapshot '$SNAPSHOT' into $TARGET…"
ob_run "$ENV_JSON" "${ARGS[@]}"
echo "Done: $(find "$TARGET" -type f | wc -l) file(s) in $TARGET"
