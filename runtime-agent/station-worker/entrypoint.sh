#!/bin/sh
set -eu

: "${STATION_ID:?STATION_ID is required}"
: "${STATION_NAME:?STATION_NAME is required}"
: "${STATION_MOUNT:?STATION_MOUNT is required}"
: "${SOURCE_USERNAME:?SOURCE_USERNAME is required}"
: "${SOURCE_PASSWORD_FILE:?SOURCE_PASSWORD_FILE is required}"
: "${ICECAST_HOST:?ICECAST_HOST is required}"
: "${ICECAST_PORT:?ICECAST_PORT is required}"
: "${ICECAST_STATUS_URL:?ICECAST_STATUS_URL is required}"

python3 /usr/local/bin/render_config.py

exec setpriv --reuid=ices --regid=ices --init-groups \
    /usr/bin/ices2 /run/ices.xml
