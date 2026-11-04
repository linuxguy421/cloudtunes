from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import re
import sqlite3
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from uuid import UUID


CONTROLLER_PATH = "/v1/agent/intents"
STATION_ID_RE = re.compile(r"^[1-9][0-9]{0,17}$")
SLUG_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
MAX_RESPONSE_BYTES = 2 * 1024 * 1024


class AgentError(RuntimeError):
    """Base error for runtime-agent polling and intent handling."""


class IntentValidationError(AgentError):
    """Raised when the controller returns an invalid station intent."""


@dataclass(frozen=True)
class StationIntent:
    station_id: str
    generation: int
    operation_id: str
    desired_state: str
    station: dict[str, Any]
    accepted_at: str


def sign_request(secret: str, timestamp: str, method: str, path: str, body: bytes = b"") -> str:
    if not secret:
        raise AgentError("CLOUDTUNES_RUNTIME_SHARED_SECRET is not configured")
    canonical = "\n".join(
        [timestamp, method.upper(), path, hashlib.sha256(body).hexdigest()]
    ).encode("utf-8")
    return hmac.new(secret.encode("utf-8"), canonical, hashlib.sha256).hexdigest()


def validate_intent(value: Any) -> StationIntent:
    if not isinstance(value, dict):
        raise IntentValidationError("Intent must be an object")

    station = value.get("station")
    if not isinstance(station, dict):
        raise IntentValidationError("Intent station descriptor is missing")

    station_id = value.get("station_id")
    generation = value.get("runtime_generation")
    operation_id = value.get("operation_id")
    desired_state = value.get("desired_state")

    if not isinstance(station_id, str) or not STATION_ID_RE.fullmatch(station_id):
        raise IntentValidationError("Intent station ID is invalid")
    if station.get("id") != station_id:
        raise IntentValidationError("Intent station ID does not match its descriptor")
    if not isinstance(generation, int) or isinstance(generation, bool) or generation < 1:
        raise IntentValidationError("Intent runtime generation is invalid")
    if station.get("runtime_generation") != generation:
        raise IntentValidationError("Intent generation does not match its descriptor")
    if not isinstance(operation_id, str):
        raise IntentValidationError("Intent operation ID is invalid")
    try:
        UUID(operation_id)
    except ValueError as exc:
        raise IntentValidationError("Intent operation ID is invalid") from exc
    if desired_state not in ("running", "stopped") or station.get("desired_state") != desired_state:
        raise IntentValidationError("Intent desired state is invalid")
    if station.get("mount_path") != f"/stations/{station_id}.ogg":
        raise IntentValidationError("Intent mount path is not canonical")
    if station.get("playback_mode") != "random":
        raise IntentValidationError("Intent playback mode is unsupported")
    name = station.get("name")
    slug = station.get("slug")
    if not isinstance(name, str) or not name.strip() or len(name) > 120:
        raise IntentValidationError("Intent station name is invalid")
    if not isinstance(slug, str) or len(slug) > 80 or not SLUG_RE.fullmatch(slug):
        raise IntentValidationError("Intent station slug is invalid")
    accepted_at = value.get("accepted_at")
    if not isinstance(accepted_at, str):
        raise IntentValidationError("Intent accepted_at timestamp is missing")

    return StationIntent(
        station_id=station_id,
        generation=generation,
        operation_id=operation_id,
        desired_state=desired_state,
        station=station,
        accepted_at=accepted_at,
    )


class ControllerClient:
    def __init__(self, base_url: str, secret: str, timeout: float = 10.0) -> None:
        self.base_url = base_url.rstrip("/")
        self.secret = secret
        self.timeout = timeout
        if not self.base_url.startswith(("http://", "https://")):
            raise AgentError("RUNTIME_CONTROLLER_URL must be an HTTP(S) URL")

    def fetch_intents(self) -> list[StationIntent]:
        timestamp = str(int(time.time()))
        signature = sign_request(self.secret, timestamp, "GET", CONTROLLER_PATH)
        request = urllib.request.Request(
            self.base_url + CONTROLLER_PATH,
            method="GET",
            headers={
                "Accept": "application/json",
                "X-CloudTunes-Timestamp": timestamp,
                "X-CloudTunes-Signature": signature,
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                body = response.read(MAX_RESPONSE_BYTES + 1)
        except (urllib.error.URLError, TimeoutError) as exc:
            raise AgentError(f"Unable to retrieve runtime intents: {exc}") from exc

        if len(body) > MAX_RESPONSE_BYTES:
            raise AgentError("Runtime controller response exceeds the configured size limit")
        try:
            payload = json.loads(body)
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise AgentError("Runtime controller returned invalid JSON") from exc
        if not isinstance(payload, dict) or not isinstance(payload.get("intents"), list):
            raise AgentError("Runtime controller response has an invalid shape")
        return [validate_intent(item) for item in payload["intents"]]


class IntentStore:
    """Durable inbox: intents remain pending until an executor acknowledges them."""

    def __init__(self, database_path: str) -> None:
        self.database_path = database_path
        path = Path(database_path)
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(path.parent, 0o700)
        previous_umask = os.umask(0o077)
        try:
            with sqlite3.connect(database_path) as connection:
                connection.execute(
                    """
                    CREATE TABLE IF NOT EXISTS desired_station_intents (
                        station_id TEXT PRIMARY KEY,
                        runtime_generation INTEGER NOT NULL,
                        operation_id TEXT NOT NULL,
                        desired_state TEXT NOT NULL,
                        descriptor_json TEXT NOT NULL,
                        accepted_at TEXT NOT NULL,
                        status TEXT NOT NULL DEFAULT 'pending',
                        last_error TEXT,
                        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )
                    """
                )
        finally:
            os.umask(previous_umask)

    def save(self, intent: StationIntent) -> bool:
        descriptor = json.dumps(intent.station, sort_keys=True, separators=(",", ":"))
        with sqlite3.connect(self.database_path, timeout=10) as connection:
            connection.execute("BEGIN IMMEDIATE")
            current = connection.execute(
                """
                SELECT runtime_generation, operation_id, desired_state, descriptor_json
                FROM desired_station_intents WHERE station_id = ?
                """,
                (intent.station_id,),
            ).fetchone()

            if current:
                current_generation = int(current[0])
                if intent.generation < current_generation:
                    raise IntentValidationError("Controller returned a stale station generation")
                if intent.generation == current_generation:
                    if current[1] != intent.operation_id or current[2] != intent.desired_state or current[3] != descriptor:
                        raise IntentValidationError("Controller reused a station generation for different intent data")
                    connection.commit()
                    return False

            connection.execute(
                """
                INSERT INTO desired_station_intents (
                    station_id, runtime_generation, operation_id, desired_state,
                    descriptor_json, accepted_at, status, last_error, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL, CURRENT_TIMESTAMP)
                ON CONFLICT(station_id) DO UPDATE SET
                    runtime_generation = excluded.runtime_generation,
                    operation_id = excluded.operation_id,
                    desired_state = excluded.desired_state,
                    descriptor_json = excluded.descriptor_json,
                    accepted_at = excluded.accepted_at,
                    status = 'pending',
                    last_error = NULL,
                    updated_at = CURRENT_TIMESTAMP
                """,
                (
                    intent.station_id,
                    intent.generation,
                    intent.operation_id,
                    intent.desired_state,
                    descriptor,
                    intent.accepted_at,
                ),
            )
            connection.commit()
            return True

    def pending(self) -> list[StationIntent]:
        with sqlite3.connect(self.database_path, timeout=5) as connection:
            rows = connection.execute(
                """
                SELECT station_id, runtime_generation, operation_id, desired_state,
                       descriptor_json, accepted_at
                FROM desired_station_intents
                WHERE status = 'pending'
                ORDER BY CAST(station_id AS INTEGER)
                """
            ).fetchall()
        return [
            StationIntent(
                station_id=row[0],
                generation=int(row[1]),
                operation_id=row[2],
                desired_state=row[3],
                station=json.loads(row[4]),
                accepted_at=row[5],
            )
            for row in rows
        ]


class RuntimeAgent:
    def __init__(self, client: ControllerClient, store: IntentStore) -> None:
        self.client = client
        self.store = store

    def poll_once(self) -> list[StationIntent]:
        intents = self.client.fetch_intents()
        for intent in intents:
            self.store.save(intent)
        return self.store.pending()


def main() -> None:
    logging.basicConfig(
        level=os.getenv("LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    controller_url = os.getenv("RUNTIME_CONTROLLER_URL", "")
    secret = os.getenv("CLOUDTUNES_RUNTIME_SHARED_SECRET", "")
    database_path = os.getenv("RUNTIME_AGENT_DB", "/data/runtime-agent.sqlite3")
    try:
        poll_interval = max(2, int(os.getenv("RUNTIME_AGENT_POLL_INTERVAL", "5")))
    except ValueError as exc:
        raise SystemExit("RUNTIME_AGENT_POLL_INTERVAL must be an integer") from exc

    try:
        agent = RuntimeAgent(
            ControllerClient(controller_url, secret),
            IntentStore(database_path),
        )
    except (AgentError, OSError, sqlite3.Error) as exc:
        raise SystemExit(str(exc)) from exc

    logging.info("Runtime agent poller started; executor is not enabled in this phase")
    while True:
        try:
            pending = agent.poll_once()
            logging.info(
                "Polled controller; %d pending station intent(s)",
                len(pending),
            )
            for intent in pending:
                logging.info(
                    "Pending station=%s generation=%d desired_state=%s operation=%s",
                    intent.station_id,
                    intent.generation,
                    intent.desired_state,
                    intent.operation_id,
                )
        except (AgentError, OSError, sqlite3.Error) as exc:
            logging.error("Runtime intent poll failed: %s", exc)
        time.sleep(poll_interval)


if __name__ == "__main__":
    main()
