#!/usr/bin/env python3
"""Docker health check: succeed only when this station is a live Icecast source."""

import json
import os
import sys
import urllib.request
from urllib.parse import urlparse

url = os.environ["ICECAST_STATUS_URL"]
mount = os.environ["STATION_MOUNT"]

try:
    with urllib.request.urlopen(url, timeout=3) as response:
        status = json.load(response)

    sources = status.get("icestats", {}).get("source", [])
    if isinstance(sources, dict):
        sources = [sources]

    for source in sources:
        listen_url = source.get("listenurl", "")
        source_mount = source.get("mount", "")
        if source_mount == mount or urlparse(listen_url).path == mount:
            sys.exit(0)
except Exception:
    pass

sys.exit(1)
