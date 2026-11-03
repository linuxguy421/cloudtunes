import hashlib
import hmac
import json
import time
from uuid import uuid4

import pytest
from fastapi.testclient import TestClient

from app import create_app


SECRET = "test-runtime-controller-secret"


@pytest.fixture()
def client(tmp_path):
    app = create_app(str(tmp_path / "runtime.sqlite3"), SECRET)
    with TestClient(app) as test_client:
        yield test_client


def signed_headers(path: str, body: bytes, timestamp: int | None = None) -> dict[str, str]:
    stamp = str(int(time.time()) if timestamp is None else timestamp)
    canonical = "\n".join(
        [stamp, "POST", path, hashlib.sha256(body).hexdigest()]
    ).encode()
    signature = hmac.new(SECRET.encode(), canonical, hashlib.sha256).hexdigest()
    return {
        "Content-Type": "application/json",
        "X-CloudTunes-Timestamp": stamp,
        "X-CloudTunes-Signature": signature,
    }


def payload(
    *,
    station_id: str = "12",
    generation: int = 1,
    desired_state: str = "running",
    operation_id: str | None = None,
) -> dict:
    return {
        "operation_id": operation_id or str(uuid4()),
        "station": {
            "id": station_id,
            "name": "Night Drive",
            "slug": "night-drive",
            "mount_path": "/night-drive.ogg",
            "playback_mode": "random",
            "desired_state": desired_state,
            "runtime_generation": generation,
        },
    }


def post_intent(client: TestClient, data: dict):
    path = f"/v1/stations/{data['station']['id']}/reconcile"
    body = json.dumps(data, separators=(",", ":")).encode()
    return client.post(path, content=body, headers=signed_headers(path, body))


def test_health_endpoint_reports_storage_ready(client):
    assert client.get("/healthz").json() == {"status": "ok"}


def test_valid_intent_is_durably_accepted(client):
    data = payload()
    response = post_intent(client, data)

    assert response.status_code == 202
    assert response.json()["accepted"] is True
    assert response.json()["operation_id"] == data["operation_id"]
    assert response.json()["runtime_generation"] == 1


def test_same_exact_operation_is_idempotent(client):
    data = payload()
    first = post_intent(client, data)
    second = post_intent(client, data)

    assert first.status_code == second.status_code == 202
    assert "already recorded" in second.json()["message"]


def test_stale_generation_is_rejected(client):
    current = payload(generation=3, desired_state="stopped")
    stale = payload(generation=2, desired_state="running")

    assert post_intent(client, current).status_code == 202
    response = post_intent(client, stale)

    assert response.status_code == 409
    assert "Stale" in response.json()["detail"]


def test_same_generation_cannot_be_reused_for_another_operation(client):
    first = payload(generation=4)
    second = payload(generation=4)

    assert post_intent(client, first).status_code == 202
    response = post_intent(client, second)

    assert response.status_code == 409


def test_newer_generation_supersedes_current_intent(client):
    first = payload(generation=1)
    second = payload(generation=8, desired_state="stopped")

    assert post_intent(client, first).status_code == 202
    assert post_intent(client, second).status_code == 202


def test_bad_signature_is_rejected(client):
    data = payload()
    path = f"/v1/stations/{data['station']['id']}/reconcile"
    body = json.dumps(data).encode()
    headers = signed_headers(path, body)
    headers["X-CloudTunes-Signature"] = "0" * 64

    assert client.post(path, content=body, headers=headers).status_code == 401


def test_expired_signature_is_rejected(client):
    data = payload()
    path = f"/v1/stations/{data['station']['id']}/reconcile"
    body = json.dumps(data).encode()
    headers = signed_headers(path, body, timestamp=int(time.time()) - 600)

    assert client.post(path, content=body, headers=headers).status_code == 401


def test_mount_path_must_match_canonical_station_slug(client):
    data = payload()
    data["station"]["mount_path"] = "/other-station.ogg"

    assert post_intent(client, data).status_code == 422


def test_station_id_must_match_signed_request_path(client):
    data = payload(station_id="12")
    data["station"]["id"] = "13"

    assert post_intent(client, data).status_code == 422


def test_extra_station_fields_are_rejected(client):
    data = payload()
    data["station"]["source_password"] = "must-not-be-accepted"

    assert post_intent(client, data).status_code == 422
