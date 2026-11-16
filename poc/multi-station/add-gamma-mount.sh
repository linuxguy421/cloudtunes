#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIR"

SECRET="$SCRIPT_DIR/secrets/gamma_source_password"
if [ ! -s "$SECRET" ]; then
    echo "Missing Gamma source password. Start the POC first with: sh ./poc/multi-station/up.sh" >&2
    exit 1
fi

ICECAST_CID=$(docker compose -f compose.yaml ps -q icecast)
if [ -z "$ICECAST_CID" ]; then
    echo "The POC Icecast container is not running." >&2
    exit 1
fi

# The POC Icecast container was intentionally started without the Gamma
# credential. Copy it into this disposable container only for the live-reload
# test; it is not added to the production Icecast service.
docker cp "$SECRET" "$ICECAST_CID:/run/gamma_source_password"
docker exec "$ICECAST_CID" chmod 0600 /run/gamma_source_password

docker exec -i "$ICECAST_CID" python3 - <<'PY'
import os
import pwd
import signal
import shutil
import xml.etree.ElementTree as ET
from pathlib import Path

config = Path("/run/icecast.xml")
secret_file = Path("/run/gamma_source_password")
backup = Path("/run/icecast.xml.before-gamma")

password = secret_file.read_text(encoding="utf-8").strip()
if not (32 <= len(password) <= 128) or not all(
    char.isalnum() or char in "_-" for char in password
):
    raise SystemExit("Gamma source password has an invalid format")

tree = ET.parse(config)
root = tree.getroot()
for mount in root.findall("mount"):
    if mount.findtext("mount-name") == "/station-gamma.ogg":
        raise SystemExit("Gamma mount is already present; refusing to add a duplicate")

# Icecast is run with -n (foreground). Find its process explicitly rather
# than signalling the su wrapper, which may be PID 1 in the container.
matches = []
for entry in Path("/proc").iterdir():
    if not entry.name.isdigit():
        continue
    try:
        argv = (entry / "cmdline").read_bytes().split(b"\0")
    except (OSError, PermissionError):
        continue
    if argv and argv[0].rstrip(b"\0").endswith(b"/icecast2"):
        matches.append(int(entry.name))
if len(matches) != 1:
    raise SystemExit(f"Expected exactly one Icecast process; found {len(matches)}")

if not backup.exists():
    shutil.copy2(config, backup)

mount = ET.SubElement(root, "mount")
ET.SubElement(mount, "mount-name").text = "/station-gamma.ogg"
ET.SubElement(mount, "username").text = "gamma-source"
ET.SubElement(mount, "password").text = password

ET.indent(root, space="    ")
temporary = config.with_suffix(".xml.new")
tree.write(temporary, encoding="utf-8", xml_declaration=True)

account = pwd.getpwnam("icecast2")
os.chown(temporary, account.pw_uid, account.pw_gid)
os.chmod(temporary, 0o600)
os.replace(temporary, config)

os.kill(matches[0], signal.SIGHUP)
print("Added the Gamma mount to the disposable Icecast config and sent SIGHUP.")
PY

echo "Icecast stayed in place; inspect its logs before starting Gamma:"
docker compose -f compose.yaml logs --tail=30 icecast
