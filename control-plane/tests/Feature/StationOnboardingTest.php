<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The Compose test runner inherits APP_ENV=local, so Laravel's automatic
        // unit-test CSRF bypass is not active. Exempt these API routes only in
        // this test process; production CSRF middleware remains unchanged.
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['api/v1/*']);
    }

    protected function tearDown(): void
    {
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::flushState();

        parent::tearDown();
    }

    public function test_registration_creates_a_pending_user_and_normalizes_email(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'New Listener',
            'email' => ' New@Example.test ',
            'password' => 'a-long-test-password',
            'password_confirmation' => 'a-long-test-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'new@example.test')
            ->assertJsonPath('data.approval_status', 'pending');

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.test',
            'role' => 'user',
            'approval_status' => 'pending',
        ]);

        $this->postJson('/api/v1/register', [
            'name' => 'Duplicate Listener',
            'email' => 'NEW@example.test',
            'password' => 'another-long-password',
            'password_confirmation' => 'another-long-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_pending_user_cannot_create_a_station(): void
    {
        $user = User::query()->create([
            'name' => 'Pending User',
            'email' => 'pending@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/stations', ['name' => 'Not Yet'])
            ->assertForbidden();

        $this->assertDatabaseCount('stations', 0);
    }

    public function test_admin_can_approve_user_and_user_can_create_station(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'a-long-test-password',
            'role' => 'admin',
        ]);
        $user = User::query()->create([
            'name' => 'Approved User',
            'email' => 'approved@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$user->id}/approval", [
                'approval_status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $user->refresh();

        $this->actingAs($user)
            ->postJson('/api/v1/stations', ['name' => 'Synthwave Radio'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'synthwave-radio')
            ->assertJsonPath('data.mount_path', '/synthwave-radio.ogg')
            ->assertJsonPath('data.status', 'draft');

        $this->postJson('/api/v1/stations', ['name' => 'Synthwave Radio'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'synthwave-radio-2')
            ->assertJsonPath('data.mount_path', '/synthwave-radio-2.ogg');

        $this->assertDatabaseHas('stations', [
            'user_id' => $user->id,
            'slug' => 'synthwave-radio',
            'desired_state' => 'stopped',
        ]);
        $this->assertDatabaseCount('stations', 2);
    }

    public function test_non_admin_cannot_approve_users(): void
    {
        $user = User::query()->create([
            'name' => 'Regular User',
            'email' => 'regular@example.test',
            'password' => 'a-long-test-password',
        ]);
        $target = User::query()->create([
            'name' => 'Target',
            'email' => 'target@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/v1/admin/users/{$target->id}/approval", [
                'approval_status' => 'approved',
            ])
            ->assertForbidden();
    }

    public function test_login_accepts_valid_credentials_and_logout_ends_session(): void
    {
        $user = User::query()->create([
            'name' => 'Listener',
            'email' => 'listener@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'LISTENER@example.test',
            'password' => 'a-long-test-password',
        ])->assertOk()
            ->assertJsonPath('data.email', 'listener@example.test')
            ->assertJsonPath('data.approval_status', 'pending');

        $this->assertAuthenticatedAs($user);

        $this->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Signed out.');

        $this->assertGuest();
    }

    public function test_login_rejects_invalid_credentials_and_rejected_accounts(): void
    {
        $user = User::query()->create([
            'name' => 'Listener',
            'email' => 'listener@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'listener@example.test',
            'password' => 'incorrect-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $user->forceFill(['approval_status' => 'rejected'])->save();

        $this->postJson('/api/v1/login', [
            'email' => 'listener@example.test',
            'password' => 'a-long-test-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_station_and_approval_routes_require_authentication(): void
    {
        $target = User::query()->create([
            'name' => 'Target',
            'email' => 'target@example.test',
            'password' => 'a-long-test-password',
        ]);

        $this->getJson('/api/v1/stations')->assertUnauthorized();
        $this->postJson('/api/v1/stations', ['name' => 'Unauthenticated'])->assertUnauthorized();
        $this->patchJson("/api/v1/admin/users/{$target->id}/approval", [
            'approval_status' => 'approved',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('stations', 0);
    }

    public function test_station_list_only_returns_the_authenticated_users_stations(): void
    {
        $owner = User::query()->create([
            'name' => 'Station Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-test-password',
        ]);
        $otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => 'other@example.test',
            'password' => 'a-long-test-password',
        ]);

        Station::query()->create([
            'user_id' => $owner->id,
            'name' => 'Owner Station',
            'slug' => 'owner-station',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/owner-station.ogg',
        ]);
        Station::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Private Station',
            'slug' => 'private-station',
            'status' => Station::STATUS_DRAFT,
            'desired_state' => Station::DESIRED_STOPPED,
            'playback_mode' => 'random',
            'mount_path' => '/private-station.ogg',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/stations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'owner-station')
            ->assertJsonMissing(['slug' => 'private-station']);
    }
}
