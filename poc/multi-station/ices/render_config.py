#!/usr/bin/env python3
"""Render one foreground Ices configuration from station environment and secret."""

import os
import re
import xml.etree.ElementTree as ET
from pathlib import Path

password_path = Path(os.environ["SOURCE_PASSWORD_FILE"])
try:
    password = password_path.read_text(encoding="utf-8").strip()
except OSError as exc:
    raise SystemExit(f"Cannot read station source secret: {password_path.name}") from exc

if not re.fullmatch(r"[A-Za-z0-9_-]{32,128}", password):
    raise SystemExit("Station source password must be 32-128 safe ASCII characters")

mount = os.environ["STATION_MOUNT"]
if not mount.startswith("/") or any(char in mount for char in "\r\n "):
    raise SystemExit("STATION_MOUNT must be an absolute mount path without whitespace")

root = ET.Element("ices")
ET.SubElement(root, "background").text = "0"
ET.SubElement(root, "logpath").text = "/var/log/ices"
ET.SubElement(root, "logfile").text = "ices.log"
ET.SubElement(root, "loglevel").text = "4"
ET.SubElement(root, "consolelog").text = "1"

stream = ET.SubElement(root, "stream")
metadata = ET.SubElement(stream, "metadata")
ET.SubElement(metadata, "name").text = os.environ["STATION_NAME"]
ET.SubElement(metadata, "genre").text = "Test"
ET.SubElement(metadata, "description").text = os.environ.get(
    "STATION_DESCRIPTION", "CloudTunes multi-station POC"
)

input_config = ET.SubElement(stream, "input")
ET.SubElement(input_config, "module").text = "playlist"
for name, value in (
    ("type", "basic"),
    ("file", "/playlist/playlist.txt"),
    ("random", "1"),
    ("restart-after-reread", "0"),
    ("once", "0"),
):
    ET.SubElement(input_config, "param", {"name": name}).text = value

instance = ET.SubElement(stream, "instance")
for tag, value in (
    ("hostname", os.environ.get("ICECAST_HOST", "icecast")),
    ("port", os.environ.get("ICECAST_PORT", "8000")),
    ("username", os.environ["SOURCE_USERNAME"]),
    ("password", password),
    ("mount", mount),
    ("reconnectdelay", "5"),
    ("reconnectattempts", "0"),
    ("maxqueuelength", "80"),
):
    ET.SubElement(instance, tag).text = value

encode = ET.SubElement(instance, "encode")
for tag, value in (
    ("nominal-bitrate", "128000"),
    ("samplerate", "44100"),
    ("channels", "2"),
):
    ET.SubElement(encode, tag).text = value

ET.indent(root, space="    ")
output = Path("/run/ices.xml")
ET.ElementTree(root).write(output, encoding="utf-8", xml_declaration=True)
os.chmod(output, 0o600)
