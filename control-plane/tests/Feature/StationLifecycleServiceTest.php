<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Services\StationLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class StationLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function station(array $attributes = []): Station
    {
        $owner = User::query()->create([
            'name' => 'Station Owner',
            'email' => Str::uuid().'@example.test',
            'password' => 'a-long-test-password',
        ]);

        return Station::query()->create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Lifecycle Test',
            'slug' => 'lifecycle-'.Str::lower(Str::random(8)),
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/lifecycle-'.Str::lower(Str::random(8)).'.ogg',
            'configuration' => ['playlist' => 'default'],
        ], $attributes));
    }

    public function test_start_request_records_intent_without_claiming_station_is_live(): void
    {
        $station = $this->station();
        $actor = User::query()->create([
            'name' => 'Lifecycle Actor',
            'email' => Str::uuid().'@example.test',
            'password' => 'a-long-test-password',
        ]);
        $result = app(StationLifecycleService::class)->requestStart($station, $actor->id);

        $this->assertTrue($result['changed']);
        $this->assertSame(Station::DESIRED_RUNNING, $result['station']->desired_state);
        $this->assertSame(Station::STATUS_PROVISIONING, $result['station']->status);
        $this->assertNotSame(Station::STATUS_LIVE, $result['station']->status);

        $this->assertDatabaseHas('station_lifecycle_events', [
            'station_id' => $station->id,
            'operation_id' => $result['operation_id'],
            'actor_user_id' => $actor->id,
            'event_type' => 'start_requested',
            'from_state' => Station::STATUS_DRAFT,
            'to_state' => Station::STATUS_PROVISIONING,
        ]);
    }

    public function test_repeated_start_request_is_idempotent(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);

        $first = $service->requestStart($station);
        $second = $service->requestStart($station->fresh());

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertDatabaseCount('station_lifecycle_events', 1);
    }

    public function test_stopping_a_draft_station_is_an_idempotent_no_op(): void
    {
        $station = $this->station(['configuration' => ['playlist' => 'my-music']]);
        $result = app(StationLifecycleService::class)->requestStop($station);

        $this->assertFalse($result['changed']);
        $this->assertSame(Station::DESIRED_STOPPED, $result['station']->desired_state);
        $this->assertSame(Station::STATUS_DRAFT, $result['station']->status);
        $this->assertSame(['playlist' => 'my-music'], $result['station']->configuration);
        $this->assertDatabaseCount('station_lifecycle_events', 0);
    }

    public function test_stop_request_moves_active_station_to_stopping(): void
    {
        $station = $this->station([
            'status' => Station::STATUS_LIVE,
            'desired_state' => Station::DESIRED_RUNNING,
            'configuration' => ['playlist' => 'my-music'],
        ]);

        $result = app(StationLifecycleService::class)->requestStop($station);

        $this->assertSame(Station::DESIRED_STOPPED, $result['station']->desired_state);
        $this->assertSame(Station::STATUS_STOPPING, $result['station']->status);
        $this->assertSame(['playlist' => 'my-music'], $result['station']->configuration);
    }

    public function test_repeated_stop_request_is_idempotent_while_stopping(): void
    {
        $station = $this->station([
            'status' => Station::STATUS_LIVE,
            'desired_state' => Station::DESIRED_RUNNING,
        ]);
        $service = app(StationLifecycleService::class);

        $first = $service->requestStop($station);
        $second = $service->requestStop($station->fresh());

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertDatabaseCount('station_lifecycle_events', 1);
    }

    public function test_runtime_cannot_report_live_after_stop_was_requested(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);
        $start = $service->requestStart($station);
        $service->requestStop($station);

        $this->expectException(LogicException::class);
        $service->reportObservedState($station, Station::STATUS_LIVE, $start['operation_id']);
    }

    public function test_runtime_observation_records_health_and_event(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);
        $start = $service->requestStart($station);

        $updated = $service->reportObservedState(
            $station,
            Station::STATUS_LIVE,
            $start['operation_id'],
            'Source connected and mount is visible.',
        );

        $this->assertSame(Station::STATUS_LIVE, $updated->status);
        $this->assertNotNull($updated->last_health_check_at);
        $this->assertNull($updated->last_error);
        $this->assertDatabaseHas('station_lifecycle_events', [
            'station_id' => $station->id,
            'event_type' => 'runtime_state_changed',
            'from_state' => Station::STATUS_PROVISIONING,
            'to_state' => Station::STATUS_LIVE,
        ]);
    }

    public function test_failed_runtime_observation_persists_safe_error_message(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);
        $start = $service->requestStart($station);
        $updated = $service->reportObservedState(
            $station,
            Station::STATUS_FAILED,
            $start['operation_id'],
            'Playback worker exited unexpectedly.',
        );

        $this->assertSame(Station::STATUS_FAILED, $updated->status);
        $this->assertSame('Playback worker exited unexpectedly.', $updated->last_error);
    }

    public function test_unknown_observed_state_is_rejected(): void
    {
        $station = $this->station();

        $this->expectException(\InvalidArgumentException::class);
        app(StationLifecycleService::class)->reportObservedState($station, 'container_deleted', (string) Str::uuid());
    }

    public function test_active_observation_requires_running_desired_state(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);
        $service->requestStart($station);
        $stop = $service->requestStop($station);

        $this->expectException(LogicException::class);
        $service->reportObservedState($station, Station::STATUS_CONNECTING, $stop['operation_id']);
    }
}
