<?php

namespace App\Services;

use App\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class StationLifecycleService
{
    /**
     * Record the intent to start a station.
     *
     * This deliberately does not start a worker. The runtime controller will
     * consume the persisted desired state in a later implementation phase.
     *
     * @return array{station: Station, operation_id: string, changed: bool}
     */
    public function requestStart(Station $station, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($station, $actorUserId): array {
            $locked = Station::query()->whereKey($station->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->desired_state === Station::DESIRED_RUNNING
                && in_array($locked->status, Station::START_PENDING_STATES, true)) {
                $lastOperation = $locked->lifecycleEvents()
                    ->where('event_type', 'start_requested')
                    ->latest('id')
                    ->value('operation_id');

                return [
                    'station' => $locked,
                    'operation_id' => $lastOperation ?: (string) Str::uuid(),
                    'changed' => false,
                ];
            }

            $operationId = (string) Str::uuid();
            $fromState = $locked->status;

            $locked->forceFill([
                'desired_state' => Station::DESIRED_RUNNING,
                'status' => Station::STATUS_PROVISIONING,
                'last_error' => null,
            ])->save();

            $locked->lifecycleEvents()->create([
                'operation_id' => $operationId,
                'actor_user_id' => $actorUserId,
                'event_type' => 'start_requested',
                'from_state' => $fromState,
                'to_state' => Station::STATUS_PROVISIONING,
                'result' => ['accepted' => true],
                'message' => 'Station start requested; runtime reconciliation is pending.',
            ]);

            return ['station' => $locked->refresh(), 'operation_id' => $operationId, 'changed' => true];
        }, 3);
    }

    /**
     * Record the intent to stop a station. Music and station configuration
     * are never modified by this operation.
     *
     * @return array{station: Station, operation_id: string, changed: bool}
     */
    public function requestStop(Station $station, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($station, $actorUserId): array {
            $locked = Station::query()->whereKey($station->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->desired_state === Station::DESIRED_STOPPED
                && in_array($locked->status, [Station::STATUS_DRAFT, Station::STATUS_STOPPED], true)) {
                $lastOperation = $locked->lifecycleEvents()
                    ->where('event_type', 'stop_requested')
                    ->latest('id')
                    ->value('operation_id');

                return [
                    'station' => $locked,
                    'operation_id' => $lastOperation ?: (string) Str::uuid(),
                    'changed' => false,
                ];
            }

            $operationId = (string) Str::uuid();
            $fromState = $locked->status;
            $targetObservedState = $fromState === Station::STATUS_DRAFT
                ? Station::STATUS_DRAFT
                : Station::STATUS_STOPPING;

            $locked->forceFill([
                'desired_state' => Station::DESIRED_STOPPED,
                'status' => $targetObservedState,
            ])->save();

            $locked->lifecycleEvents()->create([
                'operation_id' => $operationId,
                'actor_user_id' => $actorUserId,
                'event_type' => 'stop_requested',
                'from_state' => $fromState,
                'to_state' => $targetObservedState,
                'result' => ['accepted' => true],
                'message' => 'Station stop requested; runtime reconciliation is pending.',
            ]);

            return ['station' => $locked->refresh(), 'operation_id' => $operationId, 'changed' => true];
        }, 3);
    }

    /**
     * Persist a state observation reported by the trusted runtime controller.
     * Callers must provide safe, non-secret diagnostic text only.
     */
    public function reportObservedState(
        Station $station,
        string $observedState,
        ?string $message = null,
        ?string $operationId = null,
    ): Station {
        if (! in_array($observedState, Station::OBSERVED_STATES, true)) {
            throw new InvalidArgumentException("Unsupported station observed state: {$observedState}");
        }

        return DB::transaction(function () use ($station, $observedState, $message, $operationId): Station {
            $locked = Station::query()->whereKey($station->getKey())->lockForUpdate()->firstOrFail();

            $activeStates = [
                Station::STATUS_PROVISIONING,
                Station::STATUS_STARTING,
                Station::STATUS_CONNECTING,
                Station::STATUS_LIVE,
                Station::STATUS_DEGRADED,
            ];

            if (in_array($observedState, $activeStates, true)
                && $locked->desired_state !== Station::DESIRED_RUNNING) {
                throw new LogicException('An active runtime state cannot be reported while the desired state is stopped.');
            }

            if ($observedState === Station::STATUS_STOPPED
                && $locked->desired_state !== Station::DESIRED_STOPPED) {
                throw new LogicException('A stopped runtime cannot satisfy a running station request.');
            }

            $fromState = $locked->status;
            $locked->forceFill([
                'status' => $observedState,
                'last_health_check_at' => now(),
                'last_error' => in_array($observedState, [Station::STATUS_FAILED, Station::STATUS_DEGRADED], true)
                    ? $message
                    : null,
            ])->save();

            $locked->lifecycleEvents()->create([
                'operation_id' => $operationId ?: (string) Str::uuid(),
                'event_type' => 'runtime_state_changed',
                'from_state' => $fromState,
                'to_state' => $observedState,
                'result' => ['desired_state' => $locked->desired_state],
                'message' => $message,
            ]);

            return $locked->refresh();
        }, 3);
    }
}
