<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebDashboardTest extends TestCase
{
    use RefreshDatabase;

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
