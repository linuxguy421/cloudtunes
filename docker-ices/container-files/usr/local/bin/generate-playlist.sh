#!/usr/bin/env bash
set -Eeuo pipefail

MUSIC_DIR="${MUSIC_DIR:-/data/station_0}"
PLAYLIST_FILE="${PLAYLIST_FILE:-/etc/ices/playlist/default0.txt}"

if [[ ! -d "$MUSIC_DIR" ]]; then
  printf 'ERROR: music directory does not exist: %s\n' "$MUSIC_DIR" >&2
  exit 1
fi

if ! command -v sort >/dev/null 2>&1; then
  echo "ERROR: required command not found: sort" >&2
  exit 2
fi

mkdir -p "$(dirname "$PLAYLIST_FILE")"
tmp_file="$(mktemp "${PLAYLIST_FILE}.tmp.XXXXXX")"
cleanup() { rm -f -- "$tmp_file"; }
trap cleanup EXIT

mapfile -d '' tracks < <(find "$MUSIC_DIR" -type f -name '*.ogg' -print0 | LC_ALL=C sort -z)

if (( ${#tracks[@]} == 0 )); then
  printf 'ERROR: no .ogg audio files found under %s; playlist was not changed.\n' "$MUSIC_DIR" >&2
  exit 1
fi

for track in "${tracks[@]}"; do
  if [[ "$track" == *$'\n'* || "$track" == *$'\r'* ]]; then
    printf 'ERROR: audio path contains a newline and cannot be represented safely in an Ices playlist.\n' >&2
    exit 1
  fi
  printf '%s\n' "$track" >> "$tmp_file"
done

mv -f -- "$tmp_file" "$PLAYLIST_FILE"
trap - EXIT
printf 'Generated playlist with %s .ogg file(s): %s\n' "${#tracks[@]}" "$PLAYLIST_FILE"
