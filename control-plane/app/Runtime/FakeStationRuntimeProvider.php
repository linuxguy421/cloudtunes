<?php
namespace App\Runtime;
use App\Models\Station;
/** Test double: no Docker calls, process creation, or external side effects. */
class FakeStationRuntimeProvider implements StationRuntimeProvider {
 public function provision(Station $station): array { return ['accepted'=>true,'state'=>Station::STATUS_STOPPED,'message'=>'Fake provision accepted.']; }
 public function start(Station $station): array { return ['accepted'=>true,'state'=>Station::STATUS_RUNNING,'message'=>'Fake start accepted.']; }
 public function stop(Station $station): array { return ['accepted'=>true,'state'=>Station::STATUS_STOPPED,'message'=>'Fake stop accepted.']; }
 public function status(Station $station): array { return ['desired_state'=>$station->desired_state ?? Station::DESIRED_STOPPED,'observed_state'=>Station::STATUS_STOPPED,'healthy'=>false]; }
}
