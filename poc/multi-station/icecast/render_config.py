#!/usr/bin/env python3
"""Render an isolated Icecast configuration for the two-station POC."""

import os
import pwd
import secrets
import xml.etree.ElementTree as ET
from pathlib import Path

SECRET_DIR = Path("/run/secrets")
OUTPUT = Path("/run/icecast.xml")
LOG_DIR = Path("/var/log/icecast2")


def read_password(filename: str) -> str:
    path = SECRET_DIR / filename
    try:
        value = path.read_text(encoding="utf-8").strip()
    except OSError as exc:
        raise SystemExit(f"Cannot read required Docker secret: {filename}") from exc

    if not (32 <= len(value) <= 128) or not all(
        char.isalnum() or char in "_-" for char in value
    ):
        raise SystemExit(f"Secret {filename} must be 32-128 safe ASCII characters")
    return value


def add(parent: ET.Element, tag: str, value: str) -> ET.Element:
    node = ET.SubElement(parent, tag)
    node.text = value
    return node


root = ET.Element("icecast")
limits = ET.SubElement(root, "limits")
for tag, value in (
    ("clients", "100"),
    ("sources", "10"),
    ("threadpool", "5"),
    ("queue-size", "524288"),
    ("client-timeout", "30"),
    ("header-timeout", "15"),
    ("source-timeout", "10"),
):
    add(limits, tag, value)

authentication = ET.SubElement(root, "authentication")
add(authentication, "source-password", secrets.token_urlsafe(36))
add(authentication, "relay-password", secrets.token_urlsafe(36))
add(authentication, "admin-user", "admin")
add(authentication, "admin-password", secrets.token_urlsafe(36))

add(root, "hostname", "cloudtunes-multistation-poc")
listen_socket = ET.SubElement(root, "listen-socket")
add(listen_socket, "port", "8000")

for mount, username, secret_file in (
    ("/station-alpha.ogg", "alpha-source", "alpha_source_password"),
    ("/station-bravo.ogg", "bravo-source", "bravo_source_password"),
):
    mount_config = ET.SubElement(root, "mount")
    add(mount_config, "mount-name", mount)
    add(mount_config, "username", username)
    add(mount_config, "password", read_password(secret_file))

add(root, "fileserve", "1")
paths = ET.SubElement(root, "paths")
add(paths, "basedir", "/usr/share/icecast2")
add(paths, "logdir", str(LOG_DIR))
add(paths, "webroot", "/usr/share/icecast2/web")
add(paths, "adminroot", "/usr/share/icecast2/admin")

logging = ET.SubElement(root, "logging")
add(logging, "accesslog", "access.log")
add(logging, "errorlog", "error.log")
add(logging, "loglevel", "3")
add(logging, "logsize", "10000")

security = ET.SubElement(root, "security")
add(security, "chroot", "0")

ET.indent(root, space="    ")
OUTPUT.parent.mkdir(parents=True, exist_ok=True)
ET.ElementTree(root).write(OUTPUT, encoding="utf-8", xml_declaration=True)

account = pwd.getpwnam("icecast2")
LOG_DIR.mkdir(parents=True, exist_ok=True)
os.chown(LOG_DIR, account.pw_uid, account.pw_gid)
os.chown(OUTPUT, account.pw_uid, account.pw_gid)
os.chmod(OUTPUT, 0o600)
