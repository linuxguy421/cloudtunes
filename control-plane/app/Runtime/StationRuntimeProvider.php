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
     * Persist one station reconciliation intent with the trusted controller.
     *
     * Acceptance is not a runtime health observation. The controller reports
     * actual worker and Icecast state separately after reconciliation.
     *
     * @return array{accepted: bool, operation_id: string, runtime_generation: int, message?: string|null}
     */
    public function reconcile(Station $station, string $operationId): array;
}
