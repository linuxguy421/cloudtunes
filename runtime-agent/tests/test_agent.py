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
from executor import DryRunExecutor


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

    def test_dry_run_plans_start_without_touching_infrastructure(self):
        intent = validate_intent(make_intent(desired_state="running"))
        self.store.save(intent)

        plans = DryRunExecutor(self.store).process(self.store.pending())

        self.assertEqual(len(plans), 1)
        self.assertEqual(plans[0]["mode"], "dry_run")
        self.assertEqual(plans[0]["action"], "start")
        self.assertEqual(plans[0]["prior_simulated_state"], "absent")
        self.assertEqual(plans[0]["resulting_simulated_state"], "running")
        self.assertEqual(self.store.pending(), [])

    def test_initial_stopped_intent_is_a_no_op(self):
        intent = validate_intent(make_intent(desired_state="stopped"))
        self.store.save(intent)

        plans = DryRunExecutor(self.store).process(self.store.pending())

        self.assertEqual(plans[0]["action"], "no-op")
        self.assertEqual(plans[0]["prior_simulated_state"], "absent")
        self.assertEqual(plans[0]["resulting_simulated_state"], "absent")

    def test_newer_stop_intent_plans_stop_after_simulated_start(self):
        start = validate_intent(make_intent(generation=1, desired_state="running"))
        stop = validate_intent(make_intent(generation=2, desired_state="stopped"))
        executor = DryRunExecutor(self.store)

        self.store.save(start)
        self.assertEqual(executor.process(self.store.pending())[0]["action"], "start")
        self.store.save(stop)
        plans = executor.process(self.store.pending())

        self.assertEqual(plans[0]["action"], "stop")
        self.assertEqual(plans[0]["prior_simulated_state"], "running")
        self.assertEqual(plans[0]["resulting_simulated_state"], "stopped")

    def test_repeated_controller_delivery_does_not_repeat_a_plan(self):
        intent = validate_intent(make_intent())
        executor = DryRunExecutor(self.store)
        self.store.save(intent)

        self.assertEqual(len(executor.process(self.store.pending())), 1)
        self.assertFalse(self.store.save(intent))
        self.assertEqual(executor.process(self.store.pending()), [])

    def test_superseded_intent_cannot_be_planned(self):
        old = validate_intent(make_intent(generation=1))
        latest = validate_intent(make_intent(generation=2, desired_state="stopped"))
        self.store.save(old)
        self.store.save(latest)

        self.assertIsNone(self.store.plan(old))
        self.assertEqual(self.store.pending()[0].generation, 2)

    def test_simulated_state_survives_agent_restart(self):
        start = validate_intent(make_intent(generation=1, desired_state="running"))
        self.store.save(start)
        DryRunExecutor(self.store).process(self.store.pending())

        restarted_store = IntentStore(self.database)
        stop = validate_intent(make_intent(generation=2, desired_state="stopped"))
        restarted_store.save(stop)
        plans = DryRunExecutor(restarted_store).process(restarted_store.pending())

        self.assertEqual(plans[0]["action"], "stop")
        self.assertEqual(plans[0]["prior_simulated_state"], "running")


if __name__ == "__main__":
    unittest.main()
