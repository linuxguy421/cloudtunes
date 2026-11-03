<?php

namespace App\Runtime;

use App\Models\Station;

/** Deterministic test double. It never creates containers or contacts Docker. */
class FakeStationRuntimeProvider implements StationRuntimeProvider
{
    public function reconcile(Station $station, string $operationId): array
    {
        return [
            'accepted' => true,
            'observed_state' => $station->desired_state === Station::DESIRED_RUNNING
                ? Station::STATUS_LIVE
                : Station::STATUS_STOPPED,
            'message' => 'Fake runtime reconciliation completed.',
        ];
    }

    // Retained as convenience methods for existing unit-level callers.
    public function provision(Station $station): array
    {
        return ['accepted' => true, 'state' => Station::STATUS_STOPPED, 'message' => 'Fake provision accepted.'];
    }

    public function start(Station $station): array
    {
        return ['accepted' => true, 'state' => Station::STATUS_RUNNING, 'message' => 'Fake start accepted.'];
    }

    public function stop(Station $station): array
    {
        return ['accepted' => true, 'state' => Station::STATUS_STOPPED, 'message' => 'Fake stop accepted.'];
    }

    public function status(Station $station): array
    {
        return [
            'desired_state' => $station->desired_state ?? Station::DESIRED_STOPPED,
            'observed_state' => Station::STATUS_STOPPED,
            'healthy' => false,
        ];
    }
}
