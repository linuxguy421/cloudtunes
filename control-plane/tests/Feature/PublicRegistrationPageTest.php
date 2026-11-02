<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicRegistrationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_is_public_and_linked_from_login(): void
    {
        config(['cloudtunes.public_registration_enabled' => true]);

        $this->get('/login')->assertOk()->assertSee(route('register'));
        $this->get('/register')->assertOk()->assertSee('Create your account');
    }

    public function test_public_registration_creates_a_pending_regular_user_without_authenticating_them(): void
    {
        config(['cloudtunes.public_registration_enabled' => true]);

        $this->post('/register', [
            'name' => 'New Listener',
            'email' => ' New@Example.test ',
            'password' => 'a-long-test-password',
            'password_confirmation' => 'a-long-test-password',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'name' => 'New Listener',
            'email' => 'new@example.test',
            'role' => 'user',
            'approval_status' => 'pending',
        ]);

        $this->assertGuest();
    }

    public function test_registration_requires_a_valid_email_and_confirmed_twelve_character_password(): void
    {
        config(['cloudtunes.public_registration_enabled' => true]);

        $this->from('/register')->post('/register', [
            'name' => 'New Listener',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertRedirect('/register')->assertSessionHasErrors(['email', 'password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_page_and_submission_are_disabled_when_registration_is_off(): void
    {
        config(['cloudtunes.public_registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'New Listener',
            'email' => 'new@example.test',
            'password' => 'a-long-test-password',
            'password_confirmation' => 'a-long-test-password',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }
}
