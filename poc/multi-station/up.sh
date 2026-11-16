#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIR"

mkdir -p secrets data/alpha data/bravo data/gamma playlists/alpha playlists/bravo playlists/gamma

make_secret() {
    file=$1
    if [ ! -s "$file" ]; then
        (umask 077; od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$file")
    fi
    chmod 600 "$file"
}

make_secret secrets/alpha_source_password
make_secret secrets/bravo_source_password
make_secret secrets/gamma_source_password

docker compose -f compose.yaml up -d --build
