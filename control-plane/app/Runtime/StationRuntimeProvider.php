<?php
namespace App\Runtime;
use App\Models\Station;
/** Typed boundary only; never accept arbitrary shell commands or container IDs. */
interface StationRuntimeProvider {
 public function provision(Station $station): array;
 public function start(Station $station): array;
 public function stop(Station $station): array;
 public function status(Station $station): array;
}
