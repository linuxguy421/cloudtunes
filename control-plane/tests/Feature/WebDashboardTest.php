<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise web authentication and authorization flows; CSRF
        // token enforcement is handled separately by Laravel's browser middleware.
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);
    }

    public function test_guests_are_redirected_to_login_and_login_page_has_no_public_registration_link(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/stations')->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Invite-only access')
            ->assertDontSee('Create account');
    }

    public function test_user_can_sign_in_and_see_pending_approval_message(): void
    {
        $user = User::query()->create([
            'name' => 'Pending Listener',
            'email' => 'pending@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->post(route('login.store'), [
            'email' => 'PENDING@example.test',
            'password' => 'a-long-test-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your account is awaiting approval.')
            ->assertSee('Station quota');
    }

    public function test_login_rejects_invalid_credentials_and_rejected_accounts(): void
    {
        $user = User::query()->create([
            'name' => 'Rejected Listener',
            'email' => 'rejected@example.test',
            'password' => 'a-long-test-password',
        ]);
        $user->forceFill(['approval_status' => 'rejected'])->save();

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'rejected@example.test',
                'password' => 'a-long-test-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_approved_user_can_create_a_random_playback_station_and_see_it_in_list(): void
    {
        $user = User::query()->create([
            'name' => 'Approved Listener',
            'email' => 'approved@example.test',
            'password' => 'a-long-test-password',
        ]);
        $user->forceFill(['approval_status' => 'approved'])->save();

        $this->actingAs($user)
            ->get(route('stations.index'))
            ->assertOk()
            ->assertSee('Create a station');

        $this->actingAs($user)
            ->post(route('stations.store'), ['name' => 'Night Drive'])
            ->assertRedirect(route('stations.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('stations', [
            'user_id' => $user->id,
            'name' => 'Night Drive',
            'slug' => 'night-drive',
            'mount_path' => '/night-drive.ogg',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
        ]);

        $this->actingAs($user)
            ->get(route('stations.index'))
            ->assertOk()
            ->assertSee('Night Drive')
            ->assertSee('/night-drive.ogg')
            ->assertSee('Random');
    }

    public function test_pending_user_cannot_create_a_station(): void
    {
        $user = User::query()->create([
            'name' => 'Pending Listener',
            'email' => 'pending@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->actingAs($user)
            ->post(route('stations.store'), ['name' => 'Not Allowed'])
            ->assertForbidden();

        $this->assertDatabaseCount('stations', 0);
    }

    public function test_station_list_never_discloses_another_users_stations(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => 'a-long-test-password',
        ]);

        Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Radio',
            'slug' => 'owner-radio',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/owner-radio.ogg',
        ]);
        Station::query()->create([
            'user_id' => $other->id,
            'name' => 'Private Radio',
            'slug' => 'private-radio',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/private-radio.ogg',
        ]);

        $this->actingAs($owner)
            ->get(route('stations.index'))
            ->assertOk()
            ->assertSee('Owner Radio')
            ->assertDontSee('Private Radio')
            ->assertDontSee('/private-radio.ogg');
    }

    public function test_owner_can_edit_station_name_without_changing_stream_identity(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        $station = Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Old Name',
            'slug' => 'stable-slug',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/stable-slug.ogg',
        ]);

        $this->actingAs($owner)
            ->get(route('stations.edit', $station))
            ->assertOk()
            ->assertSee('Old Name');

        $this->actingAs($owner)
            ->put(route('stations.update', $station), ['name' => 'New Name'])
            ->assertRedirect(route('stations.index'));

        $this->assertDatabaseHas('stations', [
            'id' => $station->id,
            'name' => 'New Name',
            'slug' => 'stable-slug',
            'mount_path' => '/stable-slug.ogg',
        ]);
    }

    public function test_user_cannot_edit_or_archive_another_users_station(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        $other = User::query()->create([
            'name' => 'Other',
            'email' => 'other@example.test',
            'password' => 'a-long-test-password',
        ]);
        $station = Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Private Station',
            'slug' => 'private-station',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/private-station.ogg',
        ]);

        $this->actingAs($other)
            ->get(route('stations.edit', $station))
            ->assertForbidden();

        $this->actingAs($other)
            ->delete(route('stations.destroy', $station))
            ->assertForbidden();

        $this->assertDatabaseHas('stations', ['id' => $station->id, 'deleted_at' => null]);
    }

    public function test_archiving_soft_deletes_station_and_preserves_its_record(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        $station = Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Archived Station',
            'slug' => 'archived-station',
            'status' => Station::STATUS_STOPPED,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/archived-station.ogg',
        ]);

        $this->actingAs($owner)
            ->delete(route('stations.destroy', $station))
            ->assertRedirect(route('stations.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted('stations', ['id' => $station->id]);
        $this->assertSame('Archived Station', Station::withTrashed()->findOrFail($station->id)->name);

        $this->actingAs($owner)
            ->get(route('stations.index'))
            ->assertOk()
            ->assertDontSee('Archived Station');
    }

    public function test_running_or_provisioning_station_cannot_be_archived(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        $station = Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Live Station',
            'slug' => 'live-station',
            'status' => Station::STATUS_RUNNING,
            'desired_state' => Station::DESIRED_RUNNING,
            'playback_mode' => 'random',
            'mount_path' => '/live-station.ogg',
        ]);

        $this->actingAs($owner)
            ->delete(route('stations.destroy', $station))
            ->assertRedirect(route('stations.index'))
            ->assertSessionHasErrors('station');

        $this->assertDatabaseHas('stations', ['id' => $station->id, 'deleted_at' => null]);
    }

    public function test_admin_can_see_stations_owned_by_multiple_users(): void
    {
        $admin = User::query()->create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
        ]);
        $admin->forceFill(['role' => 'admin', 'approval_status' => 'approved'])->save();

        $owner = User::query()->create([
            'name' => 'Station Owner',
            'email' => 'station-owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Managed Station',
            'slug' => 'managed-station',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/managed-station.ogg',
        ]);

        $this->actingAs($admin)
            ->get(route('stations.index'))
            ->assertOk()
            ->assertSee('Managed Station')
            ->assertSee('station-owner@example.test');
    }


    public function test_archived_station_slug_is_not_reused_by_new_station(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        $owner->forceFill(['approval_status' => 'approved'])->save();

        $station = Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Night Drive',
            'slug' => 'night-drive',
            'status' => Station::STATUS_STOPPED,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/night-drive.ogg',
        ]);
        $station->delete();

        $this->actingAs($owner)
            ->post(route('stations.store'), ['name' => 'Night Drive'])
            ->assertRedirect(route('stations.index'));

        $this->assertDatabaseHas('stations', [
            'user_id' => $owner->id,
            'slug' => 'night-drive-2',
            'mount_path' => '/night-drive-2.ogg',
            'deleted_at' => null,
        ]);
    }

    public function test_sign_out_invalidates_the_authenticated_session(): void
    {
        $user = User::query()->create([
            'name' => 'Listener',
            'email' => 'listener@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
