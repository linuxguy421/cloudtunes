<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise the real web middleware with a matching session/header CSRF token.
        $token = Str::random(40);

        $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token);
    }

    public function test_registration_creates_a_pending_user(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'New Listener',
            'email' => 'new@example.test',
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
            'approval_status' => 'approved',
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

        $this->assertDatabaseHas('stations', [
            'user_id' => $user->id,
            'slug' => 'synthwave-radio',
            'desired_state' => 'stopped',
        ]);
    }

    public function test_non_admin_cannot_approve_users(): void
    {
        $user = User::query()->create([
            'name' => 'Regular User',
            'email' => 'regular@example.test',
            'password' => 'a-long-test-password',
            'approval_status' => 'approved',
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
}
