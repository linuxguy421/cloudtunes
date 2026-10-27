<?php
namespace Tests\Unit;
use App\Models\Station;
use App\Models\User;
use App\Policies\StationPolicy;
use PHPUnit\Framework\TestCase;
class StationPolicyTest extends TestCase {
 public function test_owner_can_view_station(): void { $u=new User(); $u->id=41; $s=new Station(); $s->user_id=41; $this->assertTrue((new StationPolicy())->view($u,$s)); }
 public function test_other_user_cannot_view_or_control_station(): void {
  $u=new User(); $u->id=42; $s=new Station(); $s->user_id=41; $p=new StationPolicy();
  $this->assertFalse($p->view($u,$s)); $this->assertFalse($p->update($u,$s)); $this->assertFalse($p->delete($u,$s)); $this->assertFalse($p->start($u,$s)); $this->assertFalse($p->stop($u,$s));
 }
 public function test_admin_can_view_another_users_station(): void { $u=new User(); $u->id=99; $u->role='admin'; $s=new Station(); $s->user_id=41; $this->assertTrue((new StationPolicy())->view($u,$s)); }
}
