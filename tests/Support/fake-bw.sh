#!/usr/bin/env bash
# Stands in for the Bitwarden CLI in the package tests: a real process that
# answers `bw status` and `bw get item <name>` from environment variables,
# because no vault exists here. Never used outside tests.
case "${1:-}" in
  status) printf '{"status":"%s"}' "${FAKE_BW_STATUS:-unlocked}" ;;
  get)
    if [ "${2:-}" = "item" ] && [ "${3:-}" = "${FAKE_BW_ITEM_NAME:-}" ]; then
      printf '%s' "${FAKE_BW_ITEM_JSON:-{}}"
    else
      echo "Not found." >&2; exit 1
    fi ;;
  *) echo "fake-bw: unsupported call: $*" >&2; exit 2 ;;
esac
