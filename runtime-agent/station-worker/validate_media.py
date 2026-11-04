#!/usr/bin/env python3
"""Validate media files against the current Ices Ogg Vorbis playback profile."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path
from typing import Any


class MediaValidationError(ValueError):
    """Raised when a file cannot be used by the current station worker."""


def inspect_ogg_vorbis(
    path: str | Path,
    *,
    ffprobe: str = "ffprobe",
    timeout_seconds: int = 15,
) -> dict[str, Any]:
    """Return basic audio metadata or reject media incompatible with Ices.

    The current worker accepts Ogg containers with exactly one Vorbis audio
    stream. Stereo is required because Ices is configured to encode stereo;
    mono inputs cannot be promoted by the current Ices input pipeline.
    """

    media_path = Path(path)
    if not media_path.is_file():
        raise MediaValidationError("file does not exist or is not a regular file")
    if media_path.suffix.lower() != ".ogg":
        raise MediaValidationError("file extension must be .ogg")

    command = [
        ffprobe,
        "-v",
        "error",
        "-select_streams",
        "a",
        "-show_entries",
        "format=format_name:stream=codec_type,codec_name,sample_rate,channels",
        "-of",
        "json",
        str(media_path),
    ]
    try:
        result = subprocess.run(
            command,
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout_seconds,
        )
    except FileNotFoundError as exc:
        raise MediaValidationError("ffprobe is unavailable in the station worker") from exc
    except subprocess.TimeoutExpired as exc:
        raise MediaValidationError("audio inspection timed out") from exc

    if result.returncode != 0:
        detail = (result.stderr or "").strip().splitlines()
        reason = detail[-1][:240] if detail else "ffprobe could not read the file"
        raise MediaValidationError(f"invalid or unreadable audio: {reason}")

    try:
        probe = json.loads(result.stdout)
        container_names = {
            name.strip().lower()
            for name in probe.get("format", {}).get("format_name", "").split(",")
        }
        streams = probe.get("streams", [])
    except (json.JSONDecodeError, AttributeError, TypeError) as exc:
        raise MediaValidationError("ffprobe returned invalid media metadata") from exc

    if "ogg" not in container_names:
        raise MediaValidationError("audio container must be Ogg")
    if len(streams) != 1:
        raise MediaValidationError(
            "file must contain exactly one audio stream; multiple audio streams are unsupported"
        )

    stream = streams[0]
    codec = stream.get("codec_name")
    if codec != "vorbis":
        raise MediaValidationError(
            f"audio codec must be Vorbis (detected {codec or 'unknown'})"
        )

    try:
        sample_rate = int(stream["sample_rate"])
        channels = int(stream["channels"])
    except (KeyError, TypeError, ValueError) as exc:
        raise MediaValidationError("audio stream is missing sample rate or channel metadata") from exc

    if not 8000 <= sample_rate <= 192000:
        raise MediaValidationError(
            f"sample rate {sample_rate} Hz is outside the supported 8000-192000 Hz range"
        )
    if channels != 2:
        raise MediaValidationError(
            f"audio must be stereo (2 channels); detected {channels} channel(s)"
        )

    return {
        "container": "ogg",
        "codec": codec,
        "sample_rate": sample_rate,
        "channels": channels,
    }
