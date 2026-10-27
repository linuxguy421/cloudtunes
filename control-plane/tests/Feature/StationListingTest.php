<?php
namespace Tests\Feature;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class StationListingTest extends TestCase {
 use RefreshDatabase;
 public function test_list_is_scoped_to_authenticated_user(): void {
  $a=User::query()->create(['name'=>'Alice','email'=>'alice@example.test','password'=>'test-password']);
  $b=User::query()->create(['name'=>'Bob','email'=>'bob@example.test','password'=>'test-password']);
  Station::query()->create(['user_id'=>$a->id,'name'=>'Alice Radio','slug'=>'alice-radio','mount_path'=>'/alice-radio.ogg']);
  Station::query()->create(['user_id'=>$b->id,'name'=>'Bob Radio','slug'=>'bob-radio','mount_path'=>'/bob-radio.ogg']);
  $this->actingAs($a)->getJson('/api/v1/stations')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.slug','alice-radio');
 }
 public function test_list_requires_authentication(): void { $this->getJson('/api/v1/stations')->assertUnauthorized(); }
}
