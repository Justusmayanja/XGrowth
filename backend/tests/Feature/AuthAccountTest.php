<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_and_manual_accounts_can_sign_in(): void
    {
        $this->postJson('/api/auth/register', [
            'setup' => 'email',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertCreated();

        $this->postJson('/api/auth/register', [
            'setup' => 'manual',
            'name' => 'Grace Hopper',
            'username' => 'Grace_H',
            'email' => 'grace@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertCreated()
            ->assertJsonPath('user.username', 'grace_h');

        $this->postJson('/api/auth/login', [
            'login' => 'grace_h',
            'password' => 'password1',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password1',
        ])->assertOk();
    }

    public function test_social_buttons_explain_when_they_are_not_configured(): void
    {
        config([
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
            'services.frontend.url' => 'http://localhost:3000',
        ]);

        $this->getJson('/api/auth/providers')
            ->assertOk()
            ->assertJson(['google' => false, 'x' => false]);

        $this->get('/api/auth/google/redirect')
            ->assertRedirect('http://localhost:3000/auth/callback?error='.urlencode(
                'Google sign-in is not turned on for this server yet. Use email, or write your username and details.'
            ));
    }

    public function test_taken_username_is_rejected(): void
    {
        User::factory()->create([
            'username' => 'ada',
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/auth/register', [
            'setup' => 'manual',
            'name' => 'Someone',
            'username' => 'Ada',
            'email' => 'other@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertStatus(422);
    }
}
