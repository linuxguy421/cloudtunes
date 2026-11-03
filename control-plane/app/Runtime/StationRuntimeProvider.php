<?php

namespace App\Runtime;

use App\Models\Station;

/**
 * Authenticated boundary between the Laravel queue worker and the trusted
 * runtime controller. Implementations must never accept shell commands,
 * arbitrary container IDs, host paths, or caller-supplied credentials.
 */
interface StationRuntimeProvider
{
    /**
     * Reconcile one station to its persisted desired state.
     *
     * @return array{accepted: bool, observed_state: string, message?: string|null}
     */
    public function reconcile(Station $station, string $operationId): array;
}
