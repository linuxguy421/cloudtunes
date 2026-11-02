#!/usr/bin/env bash
set -Eeuo pipefail

# Back up the isolated CloudTunes control-plane development database.
# This script deliberately refuses to dump from an unexpected Docker volume.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-${REPO_ROOT}/compose.control-plane.dev.yaml}"
BACKUP_DIR="${BACKUP_DIR:-${REPO_ROOT}/backups/control-plane}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
EXPECTED_DB_VOLUME="${EXPECTED_DB_VOLUME:-cloudtunes-control-plane-dev-db-data}"
BACKUP_PREFIX="cloudtunes-control-plane"

for command in docker gzip find; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "Required command not found: $command" >&2
    exit 1
  }
done

[[ -f "$COMPOSE_FILE" ]] || {
  echo "Compose file not found: $COMPOSE_FILE" >&2
  exit 1
}

[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || {
  echo "RETENTION_DAYS must be a non-negative integer." >&2
  exit 1
}

compose=(docker compose --project-directory "$REPO_ROOT" -f "$COMPOSE_FILE")
container_id="$("${compose[@]}" ps -q control-plane-db)"

[[ -n "$container_id" ]] || {
  echo "The control-plane-db service is not running. Start it before backing up." >&2
  exit 1
}

actual_volume="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}' "$container_id")"

if [[ "$actual_volume" != "$EXPECTED_DB_VOLUME" ]]; then
  echo "Refusing backup: expected database volume '$EXPECTED_DB_VOLUME', found '${actual_volume:-none}'." >&2
  exit 1
fi

install -d -m 700 "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_file="${BACKUP_DIR}/${BACKUP_PREFIX}-${timestamp}.sql.gz"
temporary_file="$(mktemp "${BACKUP_DIR}/.${BACKUP_PREFIX}.XXXXXX")"
trap 'rm -f "$temporary_file"' EXIT

echo "Backing up PostgreSQL database from volume: $actual_volume"
docker exec "$container_id" sh -ec 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' \
  | gzip -9 > "$temporary_file"

gzip -t "$temporary_file"
chmod 600 "$temporary_file"
mv -- "$temporary_file" "$backup_file"
trap - EXIT

find "$BACKUP_DIR" -maxdepth 1 -type f \
  -name "${BACKUP_PREFIX}-*.sql.gz" \
  -mtime "+$RETENTION_DAYS" -print -delete

echo "Backup completed: $backup_file"
