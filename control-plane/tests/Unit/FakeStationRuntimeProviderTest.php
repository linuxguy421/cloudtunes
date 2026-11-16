<?php

namespace Tests\Unit;

use App\Models\Station;
use App\Runtime\FakeStationRuntimeProvider;
use PHPUnit\Framework\TestCase;

class FakeStationRuntimeProviderTest extends TestCase
{
    public function test_fake_runtime_acknowledges_intent_without_claiming_observed_health(): void
    {
        $station = new Station();
        $station->desired_state = Station::DESIRED_RUNNING;
        $station->runtime_generation = 3;
        $operationId = 'f4d58b80-2e0a-4d0e-98c1-91ecf3cdbf34';

        $result = (new FakeStationRuntimeProvider())->reconcile($station, $operationId);

        $this->assertTrue($result['accepted']);
        $this->assertSame($operationId, $result['operation_id']);
        $this->assertSame(3, $result['runtime_generation']);
        $this->assertArrayNotHasKey('observed_state', $result);
    }

    public function test_legacy_fake_helpers_remain_deterministic_and_side_effect_free(): void
    {
        $station = new Station();
        $runtime = new FakeStationRuntimeProvider();

        $this->assertSame(Station::STATUS_RUNNING, $runtime->start($station)['state']);
        $this->assertSame(Station::STATUS_STOPPED, $runtime->stop($station)['state']);
        $this->assertSame(Station::STATUS_STOPPED, $runtime->status($station)['observed_state']);
    }
}
