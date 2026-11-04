import sys
import tempfile
import unittest
from pathlib import Path
from uuid import uuid4

# unittest discovery starts with runtime-agent/tests on sys.path, not its parent.
# Add the agent source directory so the documented discovery command works.
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from agent import (
    IntentStore,
    IntentValidationError,
    RuntimeAgent,
    sign_request,
    validate_intent,
)


def make_intent(station_id="12", generation=1, desired_state="running"):
    operation_id = str(uuid4())
    station = {
        "id": station_id,
        "name": "Night Drive",
        "slug": "night-drive",
        "mount_path": f"/stations/{station_id}.ogg",
        "playback_mode": "random",
        "desired_state": desired_state,
        "runtime_generation": generation,
    }
    return {
        "station_id": station_id,
        "runtime_generation": generation,
        "operation_id": operation_id,
        "desired_state": desired_state,
        "station": station,
        "accepted_at": "2026-11-04T00:00:00+00:00",
    }


class FakeClient:
    def __init__(self, intents):
        self.intents = intents

    def fetch_intents(self):
        return [validate_intent(item) for item in self.intents]


class AgentTests(unittest.TestCase):
    def setUp(self):
        self.tempdir = tempfile.TemporaryDirectory()
        self.database = str(Path(self.tempdir.name) / "agent.sqlite3")
        self.store = IntentStore(self.database)

    def tearDown(self):
        self.tempdir.cleanup()

    def test_signature_matches_controller_contract(self):
        signature = sign_request("shared-secret", "123", "GET", "/v1/agent/intents")
        self.assertEqual(len(signature), 64)

    def test_valid_intent_is_accepted(self):
        intent = validate_intent(make_intent())
        self.assertEqual(intent.station_id, "12")
        self.assertEqual(intent.generation, 1)

    def test_invalid_mount_is_rejected(self):
        value = make_intent()
        value["station"]["mount_path"] = "/wrong.ogg"
        with self.assertRaises(IntentValidationError):
            validate_intent(value)

    def test_descriptor_id_must_match_outer_id(self):
        value = make_intent()
        value["station"]["id"] = "13"
        with self.assertRaises(IntentValidationError):
            validate_intent(value)

    def test_same_generation_is_idempotent(self):
        intent = validate_intent(make_intent())
        self.assertTrue(self.store.save(intent))
        self.assertFalse(self.store.save(intent))
        self.assertEqual(len(self.store.pending()), 1)

    def test_newer_generation_replaces_pending_intent(self):
        first = validate_intent(make_intent(generation=1))
        second = validate_intent(make_intent(generation=2, desired_state="stopped"))
        self.store.save(first)
        self.assertTrue(self.store.save(second))
        pending = self.store.pending()
        self.assertEqual(len(pending), 1)
        self.assertEqual(pending[0].generation, 2)
        self.assertEqual(pending[0].desired_state, "stopped")

    def test_stale_generation_is_rejected(self):
        current = validate_intent(make_intent(generation=3))
        stale = validate_intent(make_intent(generation=2))
        self.store.save(current)
        with self.assertRaises(IntentValidationError):
            self.store.save(stale)

    def test_same_generation_with_different_operation_is_rejected(self):
        first = validate_intent(make_intent(generation=4))
        second = validate_intent(make_intent(generation=4))
        self.store.save(first)
        with self.assertRaises(IntentValidationError):
            self.store.save(second)

    def test_poll_persists_and_returns_pending_intents(self):
        raw = make_intent()
        agent = RuntimeAgent(FakeClient([raw]), self.store)
        pending = agent.poll_once()
        self.assertEqual(len(pending), 1)
        self.assertEqual(pending[0].station_id, "12")


if __name__ == "__main__":
    unittest.main()
