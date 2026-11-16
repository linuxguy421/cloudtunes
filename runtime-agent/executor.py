from __future__ import annotations

from typing import Any

from agent import IntentStore, StationIntent


class DryRunExecutor:
    """Build and persist reconciliation plans without changing infrastructure."""

    def __init__(self, store: IntentStore) -> None:
        self.store = store

    def process(self, intents: list[StationIntent]) -> list[dict[str, Any]]:
        plans = []
        for intent in intents:
            plan = self.store.plan(intent)
            if plan is not None:
                plans.append(plan)
        return plans
