#!/bin/sh
set -eu

python3 /usr/local/bin/render_config.py
exec su -s /bin/sh icecast2 -c 'exec /usr/bin/icecast2 -n -c /run/icecast.xml'
