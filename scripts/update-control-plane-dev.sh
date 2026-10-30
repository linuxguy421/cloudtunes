#!/usr/bin/env bash
set -Eeuo pipefail

# Safe update path for the isolated CloudTunes control-plane development stack.
# A successful database backup is required before Compose can run migrations.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-${REPO_ROOT}/compose.control-plane.dev.yaml}"

[[ -f "$COMPOSE_FILE" ]] || {
  echo "Compose file not found: $COMPOSE_FILE" >&2
  exit 1
}

export COMPOSE_FILE
"$SCRIPT_DIR/backup-control-plane-db.sh"

compose=(docker compose --project-directory "$REPO_ROOT" -f "$COMPOSE_FILE")
"${compose[@]}" up --build -d control-plane control-plane-worker
"${compose[@]}" ps
