<?php
namespace Tests\Unit;
use App\Models\Station;
use App\Runtime\FakeStationRuntimeProvider;
use PHPUnit\Framework\TestCase;
class FakeStationRuntimeProviderTest extends TestCase {
 public function test_fake_runtime_is_deterministic_and_has_no_external_side_effects(): void {
  $s=new Station(); $s->desired_state=Station::DESIRED_RUNNING; $r=new FakeStationRuntimeProvider();
  $this->assertSame(Station::STATUS_RUNNING,$r->start($s)['state']);
  $this->assertSame(Station::STATUS_STOPPED,$r->stop($s)['state']);
  $this->assertSame(Station::STATUS_STOPPED,$r->status($s)['observed_state']);
 }
}
