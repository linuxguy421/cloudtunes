#!/usr/bin/env python3
"""Health check: report healthy only while this station is a live Icecast source."""

import json
import os
import sys
import urllib.request
from urllib.parse import urlparse

status_url = os.environ.get("ICECAST_STATUS_URL", "")
mount = os.environ.get("STATION_MOUNT", "")
if not status_url or not mount:
    sys.exit(1)

try:
    request = urllib.request.Request(
        status_url,
        headers={"Accept": "application/json", "User-Agent": "CloudTunes-Station-Health/1"},
    )
    with urllib.request.urlopen(request, timeout=3) as response:
        status = json.load(response)

    sources = status.get("icestats", {}).get("source", [])
    if isinstance(sources, dict):
        sources = [sources]

    for source in sources:
        listen_url = source.get("listenurl", "")
        if source.get("mount") == mount or urlparse(listen_url).path == mount:
            sys.exit(0)
except Exception:
    pass

sys.exit(1)
