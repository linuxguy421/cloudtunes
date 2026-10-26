#!/usr/bin/env bash
set -euo pipefail
umask 077

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS_DIR="$ROOT_DIR/secrets"

command -v openssl >/dev/null 2>&1 || {
  echo "Error: openssl is required to generate secrets." >&2
  exit 1
}

mkdir -p "$SECRETS_DIR"
chmod 700 "$SECRETS_DIR"

source_file="$SECRETS_DIR/icecast_source_password"
ices_file="$SECRETS_DIR/ices_source_password"

if [[ -e "$source_file" && -e "$ices_file" ]]; then
  if ! cmp -s "$source_file" "$ices_file"; then
    echo "Error: source secret files exist but do not match. Resolve this manually; nothing was overwritten." >&2
    exit 1
  fi
elif [[ -e "$source_file" ]]; then
  cp "$source_file" "$ices_file"
elif [[ -e "$ices_file" ]]; then
  cp "$ices_file" "$source_file"
else
  openssl rand -hex 32 > "$source_file"
  cp "$source_file" "$ices_file"
fi

for name in icecast_relay_password icecast_admin_password; do
  file="$SECRETS_DIR/$name"
  if [[ ! -e "$file" ]]; then
    openssl rand -hex 32 > "$file"
  fi
done

for file in "$source_file" "$ices_file" \
  "$SECRETS_DIR/icecast_relay_password" "$SECRETS_DIR/icecast_admin_password"; do
  if ! grep -Eq '^[A-Fa-f0-9]{64}$' "$file"; then
    echo "Error: $file must contain exactly 64 hexadecimal characters. No values are printed." >&2
    exit 1
  fi
  chmod 600 "$file"
done

echo "Secret files are present and have restricted permissions."
echo "Values were not displayed. Back up these files securely before rotating them."
