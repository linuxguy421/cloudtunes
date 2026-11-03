<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Services\StationLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $actor = $station->owner;
        $result = app(StationLifecycleService::class)->requestStart($station, $actor);

        $this->assertTrue($result['changed']);
        $this->assertSame(Station::DESIRED_RUNNING, $result['station']->desired_state);
        $this->assertSame(Station::STATUS_PROVISIONING, $result['station']->status);
        $this->assertSame(1, $result['station']->runtime_generation);
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

    public function test_another_user_cannot_request_station_start(): void
    {
        $station = $this->station();
        $otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => Str::uuid().'@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->expectException(AuthorizationException::class);
        app(StationLifecycleService::class)->requestStart($station, $otherUser);
    }

    public function test_repeated_start_request_is_idempotent(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);

        $first = $service->requestStart($station, $station->owner);
        $second = $service->requestStart($station->fresh(), $station->owner);

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertSame(1, $first['station']->runtime_generation);
        $this->assertSame(1, $second['station']->runtime_generation);
        $this->assertDatabaseCount('station_lifecycle_events', 1);
    }

    public function test_stopping_a_draft_station_is_an_idempotent_no_op(): void
    {
        $station = $this->station(['configuration' => ['playlist' => 'my-music']]);
        $result = app(StationLifecycleService::class)->requestStop($station, $station->owner);

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

        $result = app(StationLifecycleService::class)->requestStop($station, $station->owner);

        $this->assertSame(Station::DESIRED_STOPPED, $result['station']->desired_state);
        $this->assertSame(Station::STATUS_STOPPING, $result['station']->status);
        $this->assertSame(1, $result['station']->runtime_generation);
        $this->assertSame(['playlist' => 'my-music'], $result['station']->configuration);
    }

    public function test_repeated_stop_request_is_idempotent_while_stopping(): void
    {
        $station = $this->station([
            'status' => Station::STATUS_LIVE,
            'desired_state' => Station::DESIRED_RUNNING,
        ]);
        $service = app(StationLifecycleService::class);

        $first = $service->requestStop($station, $station->owner);
        $second = $service->requestStop($station->fresh(), $station->owner);

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertDatabaseCount('station_lifecycle_events', 1);
    }

    public function test_each_new_lifecycle_intent_increments_runtime_generation(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);

        $start = $service->requestStart($station, $station->owner);
        $stop = $service->requestStop($start['station'], $station->owner);
        $startAgain = $service->requestStart($stop['station'], $station->owner);

        $this->assertSame(1, $start['station']->runtime_generation);
        $this->assertSame(2, $stop['station']->runtime_generation);
        $this->assertSame(3, $startAgain['station']->runtime_generation);
        $this->assertDatabaseHas('station_lifecycle_events', [
            'station_id' => $station->id,
            'operation_id' => $startAgain['operation_id'],
            'event_type' => 'start_requested',
        ]);
    }

    public function test_runtime_cannot_report_live_after_stop_was_requested(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);
        $start = $service->requestStart($station, $station->owner);
        $service->requestStop($station, $station->owner);

        $this->expectException(LogicException::class);
        $service->reportObservedState($station, Station::STATUS_LIVE, $start['operation_id']);
    }

    public function test_runtime_observation_records_health_and_event(): void
    {
        $station = $this->station();
        $service = app(StationLifecycleService::class);
        $start = $service->requestStart($station, $station->owner);

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
        $start = $service->requestStart($station, $station->owner);
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
        $service->requestStart($station, $station->owner);
        $stop = $service->requestStop($station, $station->owner);

        $this->expectException(LogicException::class);
        $service->reportObservedState($station, Station::STATUS_CONNECTING, $stop['operation_id']);
    }
}
