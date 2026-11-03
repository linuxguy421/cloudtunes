#!/bin/sh
set -eu

: "${STATION_NAME:?STATION_NAME is required}"
: "${STATION_MOUNT:?STATION_MOUNT is required}"
: "${SOURCE_USERNAME:?SOURCE_USERNAME is required}"
: "${SOURCE_PASSWORD_FILE:?SOURCE_PASSWORD_FILE is required}"
: "${TEST_TONE_HZ:?TEST_TONE_HZ is required}"

MEDIA_DIR=/media
PLAYLIST_DIR=/playlist
PLAYLIST_FILE="$PLAYLIST_DIR/playlist.txt"
LOG_DIR=/var/log/ices

mkdir -p "$MEDIA_DIR" "$PLAYLIST_DIR" "$LOG_DIR"

# Generate two distinct stereo test tracks only when no user-supplied Ogg
# media exists. Keep the legacy test-tone file untouched, but exclude it from
# the generated test playlist; it may be a mono artifact from an earlier run.
if ! find "$MEDIA_DIR" -maxdepth 1 -type f -iname '*.ogg' \
    ! -name 'poc-test-tone.ogg' \
    ! -name 'poc-test-tone-1.ogg' \
    ! -name 'poc-test-tone-2.ogg' -print -quit | grep -q .; then
    ffmpeg -hide_banner -loglevel error \
        -f lavfi -i "sine=frequency=$TEST_TONE_HZ:sample_rate=44100:duration=30" \
        -ac 2 -c:a libvorbis -q:a 3 -y "$MEDIA_DIR/poc-test-tone-1.ogg"
    ffmpeg -hide_banner -loglevel error \
        -f lavfi -i "sine=frequency=$((TEST_TONE_HZ + 110)):sample_rate=44100:duration=30" \
        -ac 2 -c:a libvorbis -q:a 3 -y "$MEDIA_DIR/poc-test-tone-2.ogg"
    printf '%s\\n' "$MEDIA_DIR/poc-test-tone-1.ogg" "$MEDIA_DIR/poc-test-tone-2.ogg" > "$PLAYLIST_FILE"
elif [ ! -s "$PLAYLIST_FILE" ]; then
    find "$MEDIA_DIR" -maxdepth 1 -type f -iname '*.ogg' \
        ! -name 'poc-test-tone.ogg' \
        ! -name 'poc-test-tone-1.ogg' \
        ! -name 'poc-test-tone-2.ogg' -print | sort > "$PLAYLIST_FILE"
fi

if [ ! -s "$PLAYLIST_FILE" ]; then
    echo "No Ogg Vorbis files found for $STATION_NAME" >&2
    exit 1
fi

python3 /usr/local/bin/render_config.py
chown ices:ices "$LOG_DIR"

exec su -s /bin/sh ices -c 'exec /usr/bin/ices2 /run/ices.xml'
