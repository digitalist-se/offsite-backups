#!/usr/bin/env bash
# Shared helpers for the host-side ddev scripts. Sourced, not executed.

ob_die() { echo "Error: $*" >&2; exit 1; }

ob_require_tools() {
  local missing=()
  for cmd in "$@"; do command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd"); done
  if [ ${#missing[@]} -gt 0 ]; then
    echo "Missing tools: ${missing[*]}" >&2
    echo "  Ubuntu/Debian: sudo apt install jq" >&2
    echo "  Bitwarden CLI: https://bitwarden.com/help/cli/ (then: bw login; bw unlock)" >&2
    exit 1
  fi
}

# Prints a JSON object built from the Bitwarden item's custom fields that hold
# connection data or secrets (AWS_HOST, AWS_BUCKET, AWS_ACCESS_KEY_ID,
# AWS_SECRET_ACCESS_KEY, RESTIC_PASSWORD, OFFSITE_BACKUP_SLACK_*): a vault item
# must not be able to pick binaries or commands. Plus NAME=VALUE overrides given as
# arguments and OFFSITE_BACKUP_CONFIG when set in the environment. Secrets stay
# in memory; nothing is written to disk.
ob_env_json() {
  local item="$1"; shift
  local status raw json missing=""
  status=$(bw status 2>/dev/null | jq -r '.status' 2>/dev/null || echo unknown)
  [ "$status" = "unlocked" ] || ob_die "Bitwarden vault is not unlocked. Run: bw unlock (and export BW_SESSION)"
  raw=$(bw get item "$item" 2>/dev/null) || ob_die "Could not fetch Bitwarden item '$item'. Try: bw sync"
  json=$(printf '%s' "$raw" | jq -c '(.fields // []) | map(select(.name | test("^(AWS_HOST|AWS_BUCKET|AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|RESTIC_PASSWORD|OFFSITE_BACKUP_SLACK_WEBHOOK_URL|OFFSITE_BACKUP_SLACK_CHANNEL)$"))) | map({(.name): (.value // "")}) | add // {}')
  local kv
  for kv in "$@"; do
    json=$(printf '%s' "$json" | jq -c --arg k "${kv%%=*}" --arg v "${kv#*=}" '. + {($k): $v}')
  done
  if [ -n "${OFFSITE_BACKUP_CONFIG:-}" ]; then
    json=$(printf '%s' "$json" | jq -c --arg v "$OFFSITE_BACKUP_CONFIG" '. + {OFFSITE_BACKUP_CONFIG: $v}')
  fi
  local name
  for name in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY RESTIC_PASSWORD; do
    [ -n "$(printf '%s' "$json" | jq -r --arg n "$name" '.[$n] // ""')" ] || missing="$missing $name"
  done
  [ -z "$missing" ] || ob_die "Bitwarden item '$item' is missing the field(s):$missing"
  printf '%s' "$json"
}

# Runs the package binary with the env JSON on stdin. OB_EXEC="ddev exec"
# (default) runs it inside the web container as one quoted command line;
# OB_EXEC="" runs it directly (tests, or when already inside the container).
ob_run() {
  local env_json="$1"; shift
  if [ -n "${OB_EXEC:-}" ]; then
    local quoted
    quoted=$(printf '%q ' "$OB_BIN" "$@" --env-stdin)
    # shellcheck disable=SC2086
    printf '%s' "$env_json" | $OB_EXEC "$quoted"
  else
    printf '%s' "$env_json" | "$OB_BIN" "$@" --env-stdin
  fi
}

# The --to/--target path as the binary sees it: relative inside the container
# (its project root is the site root), absolute when running directly.
ob_container_path() {
  if [ -n "${OB_EXEC:-}" ]; then printf '%s' "$1"; else printf '%s/%s' "$OB_ROOT" "$1"; fi
}
