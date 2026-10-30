#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
GENERATOR="$SCRIPT_DIR/../docker-ices/container-files/usr/local/bin/generate-playlist.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf -- "$TMP_DIR"' EXIT

music="$TMP_DIR/music"
playlist="$TMP_DIR/playlist/default0.txt"
mkdir -p "$music" "$(dirname "$playlist")"
printf 'old playlist\n' > "$playlist"

if MUSIC_DIR="$music" PLAYLIST_FILE="$playlist" bash "$GENERATOR" >/dev/null 2>&1; then
  echo "FAIL: empty music directory should be rejected" >&2
  exit 1
fi
grep -Fxq 'old playlist' "$playlist" || {
  echo "FAIL: existing playlist changed after empty input" >&2
  exit 1
}

printf 'not audio' > "$music/ignore.mp3"
printf 'audio' > "$music/z song.ogg"
printf 'audio' > "$music/a.ogg"

MUSIC_DIR="$music" PLAYLIST_FILE="$playlist" bash "$GENERATOR" >/dev/null
printf '%s\n' "$music/a.ogg" "$music/z song.ogg" > "$TMP_DIR/expected.txt"
cmp -s "$TMP_DIR/expected.txt" "$playlist" || {
  echo "FAIL: playlist does not contain sorted .ogg paths" >&2
  exit 1
}

if find "$(dirname "$playlist")" -maxdepth 1 -name 'default0.txt.tmp.*' -print -quit | grep -q .; then
  echo "FAIL: temporary playlist file was left behind" >&2
  exit 1
fi

echo "PASS: playlist generation tests passed"
