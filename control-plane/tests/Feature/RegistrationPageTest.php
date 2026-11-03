<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Match PublicRegistrationPageTest: this suite validates registration
        // behavior, not CSRF token generation or browser middleware behavior.
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['register']);
    }

    protected function tearDown(): void
    {
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::flushState();

        parent::tearDown();
    }

    public function test_registration_page_is_public_and_linked_from_sign_in(): void
    {
        config(['cloudtunes.public_registration_enabled' => true]);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create your account.')
            ->assertSee('Administrator approval required');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('register'));
    }

    public function test_registration_creates_a_pending_non_admin_account(): void
    {
        config(['cloudtunes.public_registration_enabled' => true]);

        $response = $this->post(route('register.store'), [
            'name' => 'New Listener',
            'email' => '  Listener@Example.com ',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ]);

        $response->assertRedirect(route('login'))->assertSessionHas('status');

        $user = User::query()->where('email', 'listener@example.com')->firstOrFail();
        $this->assertSame('user', $user->role);
        $this->assertSame('pending', $user->approval_status);
        $this->assertGuest();
    }

    public function test_registration_requires_a_confirmed_twelve_character_password(): void
    {
        config(['cloudtunes.public_registration_enabled' => true]);

        $this->from(route('register'))
            ->post(route('register.store'), [
                'name' => 'New Listener',
                'email' => 'listener@example.com',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'listener@example.com']);
    }

    public function test_registration_page_and_submission_are_hidden_when_disabled(): void
    {
        config(['cloudtunes.public_registration_enabled' => false]);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), [
            'name' => 'New Listener',
            'email' => 'listener@example.com',
            'password' => 'a-secure-password-123',
            'password_confirmation' => 'a-secure-password-123',
        ])->assertNotFound();

        $this->get(route('login'))->assertOk()->assertDontSee(route('register'));
    }
}
