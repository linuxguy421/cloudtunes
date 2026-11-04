#!/usr/bin/env python3
"""Render a single isolated, foreground Ices instance from runtime inputs."""

from __future__ import annotations

import os
import pwd
import re
import xml.etree.ElementTree as ET
from pathlib import Path

from validate_media import MediaValidationError, inspect_ogg_vorbis

STATION_ID_RE = re.compile(r"^[1-9][0-9]{0,18}$")
SECRET_RE = re.compile(r"^[A-Za-z0-9_-]{32,128}$")


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise SystemExit(f"{name} is required")
    return value


station_id = required("STATION_ID")
if not STATION_ID_RE.fullmatch(station_id):
    raise SystemExit("STATION_ID must be a positive numeric database ID")

mount = required("STATION_MOUNT")
expected_mount = f"/stations/{station_id}.ogg"
if mount != expected_mount:
    raise SystemExit(f"STATION_MOUNT must be {expected_mount}")

name = required("STATION_NAME")
if any(ord(char) < 32 for char in name):
    raise SystemExit("STATION_NAME must not contain control characters")

source_username = required("SOURCE_USERNAME")
if not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", source_username):
    raise SystemExit("SOURCE_USERNAME contains unsupported characters")

password_file = Path(required("SOURCE_PASSWORD_FILE"))
try:
    password = password_file.read_text(encoding="utf-8").strip()
except OSError as exc:
    raise SystemExit("Station source credential is unavailable") from exc
if not SECRET_RE.fullmatch(password):
    raise SystemExit("Station source credential must be 32-128 safe ASCII characters")

icecast_host = required("ICECAST_HOST")
if not re.fullmatch(r"[A-Za-z0-9_.-]{1,253}", icecast_host):
    raise SystemExit("ICECAST_HOST contains unsupported characters")

icecast_port = required("ICECAST_PORT")
if not icecast_port.isdecimal() or not 1 <= int(icecast_port) <= 65535:
    raise SystemExit("ICECAST_PORT must be between 1 and 65535")

media_dir = Path("/media")
playlist_dir = Path("/playlist")
playlist_dir.mkdir(parents=True, exist_ok=True)
playlist_file = playlist_dir / "playlist.txt"

# Preserve an explicitly supplied playlist. Otherwise build one from the
# station's mounted Ogg files. Never generate or bundle demo audio.
if not playlist_file.is_file() or playlist_file.stat().st_size == 0:
    tracks = sorted(
        path for path in media_dir.iterdir()
        if path.is_file()
        and path.suffix.lower() == ".ogg"
        and "\n" not in path.name
        and "\r" not in path.name
    )
    if not tracks:
        raise SystemExit("Station has no Ogg media; upload media before starting playback")
    playlist_file.write_text(
        "".join(f"{path}\n" for path in tracks),
        encoding="utf-8",
    )

playlist_entries = [
    line.strip() for line in playlist_file.read_text(encoding="utf-8").splitlines()
    if line.strip() and not line.lstrip().startswith("#")
]
if not playlist_entries:
    raise SystemExit("Station playlist is empty")

for entry in playlist_entries:
    path = Path(entry)
    if not path.is_absolute():
        path = media_dir / path
    try:
        resolved = path.resolve(strict=True)
        resolved.relative_to(media_dir.resolve(strict=True))
    except (OSError, ValueError) as exc:
        raise SystemExit("Playlist entries must resolve to files inside /media") from exc
    if not resolved.is_file() or resolved.suffix.lower() != ".ogg":
        raise SystemExit("Every playlist entry must reference an Ogg file in /media")
    try:
        audio = inspect_ogg_vorbis(resolved)
    except MediaValidationError as exc:
        raise SystemExit(f"Unsupported station media '{resolved.name}': {exc}") from exc
    print(
        f"Validated {resolved.name}: Ogg Vorbis, "
        f"{audio['sample_rate']} Hz, {audio['channels']} channels",
        flush=True,
    )

root = ET.Element("ices")
ET.SubElement(root, "background").text = "0"
ET.SubElement(root, "logpath").text = "/var/log/ices"
ET.SubElement(root, "logfile").text = "ices.log"
ET.SubElement(root, "loglevel").text = "3"
ET.SubElement(root, "consolelog").text = "1"

stream = ET.SubElement(root, "stream")
metadata = ET.SubElement(stream, "metadata")
ET.SubElement(metadata, "name").text = name
ET.SubElement(metadata, "genre").text = os.environ.get("STATION_GENRE", "Music")
ET.SubElement(metadata, "description").text = os.environ.get(
    "STATION_DESCRIPTION", "CloudTunes station"
)

input_config = ET.SubElement(stream, "input")
ET.SubElement(input_config, "module").text = "playlist"
for key, value in (
    ("type", "basic"),
    ("file", str(playlist_file)),
    ("random", "1" if os.environ.get("PLAYBACK_MODE", "random") == "random" else "0"),
    ("restart-after-reread", "0"),
    ("once", "0"),
):
    ET.SubElement(input_config, "param", {"name": key}).text = value

instance = ET.SubElement(stream, "instance")
for tag, value in (
    ("hostname", icecast_host),
    ("port", icecast_port),
    ("username", source_username),
    ("password", password),
    ("mount", mount),
    ("reconnectdelay", "5"),
    ("reconnectattempts", "0"),
    ("maxqueuelength", "80"),
):
    ET.SubElement(instance, tag).text = value

encode = ET.SubElement(instance, "encode")
for tag, value in (
    ("nominal-bitrate", os.environ.get("AUDIO_BITRATE", "128000")),
    ("samplerate", os.environ.get("AUDIO_SAMPLE_RATE", "44100")),
    ("channels", os.environ.get("AUDIO_CHANNELS", "2")),
):
    if not value.isdecimal() or int(value) <= 0:
        raise SystemExit(f"{tag} must be a positive integer")
    ET.SubElement(encode, tag).text = value

ET.indent(root, space="    ")
output = Path("/run/ices.xml")
ET.ElementTree(root).write(output, encoding="utf-8", xml_declaration=True)

ices_user = pwd.getpwnam("ices")
os.chown(output, ices_user.pw_uid, ices_user.pw_gid)
os.chmod(output, 0o600)
os.chown(playlist_file, ices_user.pw_uid, ices_user.pw_gid)
os.chmod(playlist_file, 0o640)
