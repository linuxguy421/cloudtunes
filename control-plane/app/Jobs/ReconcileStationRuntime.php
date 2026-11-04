<?php

namespace App\Jobs;

use App\Models\Station;
use App\Runtime\StationRuntimeProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(StationRuntimeProvider $runtime): void
    {
        $station = Station::query()->find($this->stationId);

        if (! $station) {
            return;
        }

        $latestIntent = $station->lifecycleEvents()
            ->whereIn('event_type', ['start_requested', 'stop_requested'])
            ->latest('id')
            ->first(['operation_id', 'result']);

        // A newer start/stop request supersedes this queued operation.
        if (! $latestIntent || ! is_string($latestIntent->operation_id)
            || ! hash_equals($latestIntent->operation_id, $this->operationId)) {
            return;
        }

        $result = $runtime->reconcile($station, $this->operationId);

        if (! ($result['accepted'] ?? false)
            || ($result['operation_id'] ?? null) !== $this->operationId
            || ($result['runtime_generation'] ?? null) !== (int) $station->runtime_generation) {
            throw new RuntimeException('Runtime controller did not acknowledge the current station operation.');
        }

        // Acceptance only means the controller durably recorded the desired
        // state. It must report actual Ices/Icecast observations separately.
    }
}
