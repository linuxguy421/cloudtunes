#!/usr/bin/env python3
"""Render Compose secrets into ephemeral runtime configuration files."""
import os
import re
import sys
from pathlib import Path

SECRET_ROOT = Path("/run/secrets")
TOKEN_RE = re.compile(r"^[A-Za-z0-9_-]{32,128}$")


def read_secret(name: str) -> str:
    path = SECRET_ROOT / name
    try:
        value = path.read_text(encoding="utf-8").strip()
    except OSError as exc:
        raise SystemExit(f"Required secret file unavailable: {path.name}") from exc
    if not TOKEN_RE.fullmatch(value):
        raise SystemExit(
            f"Secret {path.name} must contain 32-128 ASCII letters, digits, '_' or '-'"
        )
    return value


def replace_tag(config: str, tag: str, value: str, source_name: str) -> str:
    pattern = re.compile(rf"(<{re.escape(tag)}>)[^<]*(</{re.escape(tag)}>)")
    updated, count = pattern.subn(
        lambda match: match.group(1) + value + match.group(2), config
    )
    if count != 1:
        raise SystemExit(
            f"Expected exactly one <{tag}> element in {source_name}; found {count}"
        )
    return updated


def render_icecast() -> None:
    source = Path("/etc/icecast2/icecast.xml")
    config = source.read_text(encoding="utf-8")
    for tag, secret in (
        ("source-password", "icecast_source_password"),
        ("relay-password", "icecast_relay_password"),
        ("admin-password", "icecast_admin_password"),
    ):
        config = replace_tag(config, tag, read_secret(secret), str(source))
    target = Path("/run/icecast.xml")
    target.write_text(config, encoding="utf-8")
    os.chmod(target, 0o640)
    import pwd
    account = pwd.getpwnam("icecast2")
    # Use the account's primary GID rather than assuming a group name.
    os.chown(target, account.pw_uid, account.pw_gid)
    # Remove legacy credential values from the persistent config volume after
    # the complete runtime configuration has been written successfully.
    sanitized = source.read_text(encoding="utf-8")
    for tag in ("source-password", "relay-password", "admin-password"):
        sanitized = replace_tag(sanitized, tag, "__SET_AT_STARTUP__", str(source))
    source.write_text(sanitized, encoding="utf-8")


def render_ices() -> None:
    source = Path("/etc/ices/config/default0.xml")
    config = source.read_text(encoding="utf-8")
    config = replace_tag(
        config, "password", read_secret("ices_source_password"), str(source)
    )
    target = Path("/run/ices-default0.xml")
    target.write_text(config, encoding="utf-8")
    os.chmod(target, 0o600)
    # The named config volume may contain a legacy hardcoded password.
    # Replace it only after the runtime config is ready.
    sanitized = replace_tag(
        source.read_text(encoding="utf-8"),
        "password",
        "__SET_AT_STARTUP__",
        str(source),
    )
    source.write_text(sanitized, encoding="utf-8")


if len(sys.argv) != 2 or sys.argv[1] not in {"icecast", "ices"}:
    raise SystemExit("Usage: render-cloudtunes-secrets.py {icecast|ices}")

if sys.argv[1] == "icecast":
    render_icecast()
else:
    render_ices()
