<?php

namespace App\Jobs;

use App\Models\Station;
use App\Runtime\StationRuntimeProvider;
use App\Services\StationLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;
use RuntimeException;

class ReconcileStationRuntime implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public $afterCommit = true;

    public function __construct(
        public readonly int $stationId,
        public readonly string $operationId,
    ) {}

    public function handle(
        StationRuntimeProvider $runtime,
        StationLifecycleService $lifecycle,
    ): void {
        $station = Station::query()->find($this->stationId);

        if (! $station) {
            return;
        }

        $latestOperation = $station->lifecycleEvents()
            ->whereIn('event_type', ['start_requested', 'stop_requested'])
            ->latest('id')
            ->value('operation_id');

        // A newer start/stop request supersedes this queued operation.
        if (! is_string($latestOperation) || ! hash_equals($latestOperation, $this->operationId)) {
            return;
        }

        $result = $runtime->reconcile($station, $this->operationId);

        if (! ($result['accepted'] ?? false) || ! is_string($result['observed_state'] ?? null)) {
            throw new RuntimeException('Runtime controller did not accept the station reconciliation.');
        }

        $message = is_string($result['message'] ?? null)
            ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $result['message']), 0, 500)
            : null;

        try {
            $lifecycle->reportObservedState(
                $station,
                $result['observed_state'],
                $this->operationId,
                $message,
            );
        } catch (LogicException $exception) {
            // A newer desired-state operation won the race while the controller
            // was working. Its queued job is responsible for the final state.
            if (! str_contains($exception->getMessage(), 'stale or unknown')) {
                throw $exception;
            }
        }
    }
}
