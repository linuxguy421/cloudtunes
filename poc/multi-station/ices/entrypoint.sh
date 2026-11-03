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

# Generate a harmless test source only when no Ogg Vorbis media was supplied.
if ! find "$MEDIA_DIR" -maxdepth 1 -type f -iname '*.ogg' -print -quit | grep -q .; then
    ffmpeg -hide_banner -loglevel error \
        -f lavfi -i "sine=frequency=$TEST_TONE_HZ:sample_rate=44100:duration=30" \
        -c:a libvorbis -q:a 3 -y "$MEDIA_DIR/poc-test-tone.ogg"
fi

if [ ! -s "$PLAYLIST_FILE" ]; then
    find "$MEDIA_DIR" -maxdepth 1 -type f -iname '*.ogg' -print | sort > "$PLAYLIST_FILE"
fi

if [ ! -s "$PLAYLIST_FILE" ]; then
    echo "No Ogg Vorbis files found for $STATION_NAME" >&2
    exit 1
fi

python3 /usr/local/bin/render_config.py
chown ices:ices "$LOG_DIR"

exec su -s /bin/sh ices -c 'exec /usr/bin/ices2 /run/ices.xml'
