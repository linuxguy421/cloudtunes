from __future__ import annotations

import hashlib
import hmac
import json
import os
import re
import sqlite3
import time
from collections import defaultdict, deque
from threading import Lock
from contextlib import closing
from datetime import datetime, timezone
from pathlib import Path
from typing import Literal
from uuid import UUID

from fastapi import FastAPI, HTTPException, Request, Response
from pydantic import BaseModel, ConfigDict, Field, ValidationError


MAX_BODY_BYTES = 64 * 1024
CLOCK_SKEW_SECONDS = 60
AUTH_FAILURE_LIMIT = 10
AUTH_FAILURE_WINDOW_SECONDS = 60
SLUG_PATTERN = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
STATION_ID_PATTERN = re.compile(r"^[1-9][0-9]{0,17}$")


class StationDescriptor(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    id: str = Field(pattern=STATION_ID_PATTERN.pattern)
    name: str = Field(min_length=1, max_length=120)
    slug: str = Field(pattern=SLUG_PATTERN.pattern, max_length=80)
    mount_path: str = Field(min_length=6, max_length=160)
    playback_mode: Literal["random"]
    desired_state: Literal["running", "stopped"]
    runtime_generation: int = Field(ge=1, le=9_223_372_036_854_775_807)


class ReconcileRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    operation_id: UUID
    station: StationDescriptor


class InvalidAuthRateLimiter:
    def __init__(self) -> None:
        self._events: dict[str, deque[float]] = defaultdict(deque)
        self._lock = Lock()

    def check(self, source: str) -> None:
        cutoff = time.monotonic() - AUTH_FAILURE_WINDOW_SECONDS
        with self._lock:
            events = self._events[source]
            while events and events[0] <= cutoff:
                events.popleft()
            if len(events) >= AUTH_FAILURE_LIMIT:
                raise HTTPException(status_code=429, detail="Too many invalid runtime controller requests.")

    def record_failure(self, source: str) -> None:
        with self._lock:
            self._events[source].append(time.monotonic())


def initialize_database(database_path: str) -> None:
    path = Path(database_path)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(path.parent, 0o700)
    previous_umask = os.umask(0o077)
    try:
        with closing(sqlite3.connect(path)) as connection:
            connection.execute("PRAGMA journal_mode=WAL")
            connection.executescript(
                """
                CREATE TABLE IF NOT EXISTS station_runtime_intents (
                    station_id TEXT PRIMARY KEY,
                    runtime_generation INTEGER NOT NULL,
                    operation_id TEXT NOT NULL,
                    desired_state TEXT NOT NULL,
                    descriptor_json TEXT NOT NULL,
                    request_sha256 TEXT NOT NULL,
                    accepted_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS station_runtime_operations (
                    station_id TEXT NOT NULL,
                    runtime_generation INTEGER NOT NULL,
                    operation_id TEXT NOT NULL UNIQUE,
                    desired_state TEXT NOT NULL,
                    descriptor_json TEXT NOT NULL,
                    request_sha256 TEXT NOT NULL,
                    accepted_at TEXT NOT NULL,
                    PRIMARY KEY (station_id, runtime_generation)
                );
                """
            )
            connection.commit()
    finally:
        os.umask(previous_umask)


def verify_signature(
    *,
    secret: str,
    timestamp_header: str | None,
    signature_header: str | None,
    method: str,
    path: str,
    body: bytes,
    now: int | None = None,
) -> None:
    if not secret:
        raise HTTPException(status_code=503, detail="Runtime controller authentication is not configured.")

    if (
        timestamp_header is None
        or not timestamp_header.isascii()
        or not timestamp_header.isdecimal()
        or len(timestamp_header) > 12
        or signature_header is None
        or not re.fullmatch(r"[0-9a-f]{64}", signature_header)
    ):
        raise HTTPException(status_code=401, detail="Invalid controller authentication headers.")

    timestamp = int(timestamp_header)
    current_time = int(time.time()) if now is None else now
    if abs(current_time - timestamp) > CLOCK_SKEW_SECONDS:
        raise HTTPException(status_code=401, detail="Controller request timestamp is outside the allowed window.")

    canonical = "\n".join(
        [
            timestamp_header,
            method.upper(),
            path,
            hashlib.sha256(body).hexdigest(),
        ]
    ).encode("utf-8")
    expected = hmac.new(secret.encode("utf-8"), canonical, hashlib.sha256).hexdigest()

    if not hmac.compare_digest(expected, signature_header):
        raise HTTPException(status_code=401, detail="Invalid controller request signature.")


def persist_intent(database_path: str, request_data: ReconcileRequest, body: bytes) -> bool:
    station = request_data.station
    station_id = station.id
    generation = station.runtime_generation
    operation_id = str(request_data.operation_id)
    descriptor_json = json.dumps(
        station.model_dump(mode="json"),
        sort_keys=True,
        separators=(",", ":"),
    )
    request_hash = hashlib.sha256(body).hexdigest()
    accepted_at = datetime.now(timezone.utc).isoformat()

    if station.mount_path != f"/{station.slug}.ogg":
        raise HTTPException(status_code=422, detail="Station mount path does not match its canonical slug.")

    connection = sqlite3.connect(database_path, timeout=10, isolation_level=None)
    connection.row_factory = sqlite3.Row
    try:
        connection.execute("BEGIN IMMEDIATE")

        prior_operation = connection.execute(
            """
            SELECT station_id, runtime_generation, request_sha256
            FROM station_runtime_operations
            WHERE operation_id = ?
            """,
            (operation_id,),
        ).fetchone()

        if prior_operation is not None:
            if (
                prior_operation["station_id"] == station_id
                and prior_operation["runtime_generation"] == generation
                and prior_operation["request_sha256"] == request_hash
            ):
                connection.commit()
                return False
            raise HTTPException(status_code=409, detail="Operation UUID was already used for a different intent.")

        current = connection.execute(
            """
            SELECT runtime_generation, operation_id, request_sha256
            FROM station_runtime_intents
            WHERE station_id = ?
            """,
            (station_id,),
        ).fetchone()

        if current is not None:
            current_generation = int(current["runtime_generation"])
            if generation < current_generation:
                raise HTTPException(status_code=409, detail="Stale station runtime generation.")
            if generation == current_generation:
                if (
                    current["operation_id"] == operation_id
                    and current["request_sha256"] == request_hash
                ):
                    connection.commit()
                    return False
                raise HTTPException(status_code=409, detail="Runtime generation is already assigned to another operation.")

        connection.execute(
            """
            INSERT INTO station_runtime_operations (
                station_id, runtime_generation, operation_id, desired_state,
                descriptor_json, request_sha256, accepted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            (
                station_id,
                generation,
                operation_id,
                station.desired_state,
                descriptor_json,
                request_hash,
                accepted_at,
            ),
        )
        connection.execute(
            """
            INSERT INTO station_runtime_intents (
                station_id, runtime_generation, operation_id, desired_state,
                descriptor_json, request_sha256, accepted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(station_id) DO UPDATE SET
                runtime_generation = excluded.runtime_generation,
                operation_id = excluded.operation_id,
                desired_state = excluded.desired_state,
                descriptor_json = excluded.descriptor_json,
                request_sha256 = excluded.request_sha256,
                accepted_at = excluded.accepted_at
            """,
            (
                station_id,
                generation,
                operation_id,
                station.desired_state,
                descriptor_json,
                request_hash,
                accepted_at,
            ),
        )
        connection.commit()
        return True
    except HTTPException:
        if connection.in_transaction:
            connection.rollback()
        raise
    except sqlite3.IntegrityError as exc:
        if connection.in_transaction:
            connection.rollback()
        raise HTTPException(status_code=409, detail="Conflicting station runtime operation.") from exc
    finally:
        connection.close()


def create_app(
    database_path: str | None = None,
    shared_secret: str | None = None,
) -> FastAPI:
    db_path = database_path or os.getenv("RUNTIME_CONTROLLER_DB", "/data/runtime-controller.sqlite3")
    secret = shared_secret if shared_secret is not None else os.getenv("CLOUDTUNES_RUNTIME_SHARED_SECRET", "")
    auth_limiter = InvalidAuthRateLimiter()
    application = FastAPI(
        title="CloudTunes Runtime Controller",
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
    )

    @application.on_event("startup")
    def startup() -> None:
        initialize_database(db_path)

    @application.get("/healthz")
    def health() -> dict[str, str]:
        try:
            with closing(sqlite3.connect(db_path, timeout=2)) as connection:
                connection.execute("SELECT 1").fetchone()
        except sqlite3.Error as exc:
            raise HTTPException(status_code=503, detail="Runtime controller storage is unavailable.") from exc
        return {"status": "ok"}

    @application.post("/v1/stations/{station_id}/reconcile", status_code=202)
    async def reconcile(station_id: str, request: Request, response: Response) -> dict[str, object]:
        if not STATION_ID_PATTERN.fullmatch(station_id):
            raise HTTPException(status_code=404, detail="Station not found.")

        body_buffer = bytearray()
        async for chunk in request.stream():
            body_buffer.extend(chunk)
            if len(body_buffer) > MAX_BODY_BYTES:
                raise HTTPException(status_code=413, detail="Runtime request is too large.")
        body = bytes(body_buffer)

        source = request.client.host if request.client else "unknown"
        auth_limiter.check(source)
        try:
            verify_signature(
                secret=secret,
                timestamp_header=request.headers.get("X-CloudTunes-Timestamp"),
                signature_header=request.headers.get("X-CloudTunes-Signature"),
                method=request.method,
                path=request.url.path,
                body=body,
            )
        except HTTPException as exc:
            if exc.status_code == 401:
                auth_limiter.record_failure(source)
            raise

        try:
            payload = ReconcileRequest.model_validate_json(body)
        except ValidationError as exc:
            raise HTTPException(status_code=422, detail="Invalid station runtime request.") from exc

        if payload.station.id != station_id:
            raise HTTPException(status_code=422, detail="Station identifier does not match request path.")

        created = persist_intent(db_path, payload, body)
        response.headers["Cache-Control"] = "no-store"
        return {
            "accepted": True,
            "operation_id": str(payload.operation_id),
            "runtime_generation": payload.station.runtime_generation,
            "message": "Runtime intent durably recorded." if created else "Runtime intent was already recorded.",
        }

    return application


app = create_app()
