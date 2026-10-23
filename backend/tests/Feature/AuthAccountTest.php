<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    public function test_register_sends_a_code_and_creates_the_account(): void
    {
        $code = $this->requestCode([
            'purpose' => 'register',
            'name' => 'Grace Hopper',
            'username' => 'Grace_H',
            'email' => 'grace@example.com',
        ]);

        $this->postJson('/api/auth/email/verify', [
            'email' => 'grace@example.com',
            'code' => $code,
        ])->assertCreated()
            ->assertJsonPath('user.username', 'grace_h')
            ->assertJsonPath('user.email', 'grace@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', [
            'email' => 'grace@example.com',
            'username' => 'grace_h',
        ]);
    }

    public function test_login_is_email_and_code_only(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $code = $this->requestCode([
            'purpose' => 'login',
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/auth/email/verify', [
            'email' => 'ada@example.com',
            'code' => $code,
        ])->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password1',
        ])->assertNotFound();
    }

    public function test_code_expires_quickly_and_cannot_be_reused(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $code = $this->requestCode([
            'purpose' => 'login',
            'email' => 'ada@example.com',
        ]);

        $this->travel(11)->minutes();

        $this->postJson('/api/auth/email/verify', [
            'email' => 'ada@example.com',
            'code' => $code,
        ])->assertStatus(422);

        $fresh = $this->requestCode([
            'purpose' => 'login',
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/auth/email/verify', [
            'email' => 'ada@example.com',
            'code' => $fresh,
        ])->assertOk();

        $this->postJson('/api/auth/email/verify', [
            'email' => 'ada@example.com',
            'code' => $fresh,
        ])->assertStatus(422);
    }

    public function test_unknown_email_and_taken_username_are_rejected(): void
    {
        User::factory()->create([
            'username' => 'ada',
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/auth/email/send', [
            'purpose' => 'login',
            'email' => 'missing@example.com',
        ])->assertStatus(422);

        $this->postJson('/api/auth/email/send', [
            'purpose' => 'register',
            'name' => 'Someone',
            'username' => 'Ada',
            'email' => 'other@example.com',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_password_google_and_x_sign_in_are_gone(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertNotFound();

        $this->get('/api/auth/google/redirect')->assertNotFound();
        $this->get('/api/auth/providers')->assertNotFound();
        $this->postJson('/api/auth/social/complete', [])->assertNotFound();
    }

    public function test_blocked_account_cannot_receive_a_code(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'is_blocked' => true,
        ]);

        $this->postJson('/api/auth/email/send', [
            'purpose' => 'login',
            'email' => 'ada@example.com',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function requestCode(array $payload): string
    {
        $this->postJson('/api/auth/email/send', $payload)
            ->assertOk()
            ->assertJsonPath('expires_in', 600);

        $mail = Mail::sent(LoginCodeMail::class)->last();
        $this->assertInstanceOf(LoginCodeMail::class, $mail);
        $this->assertTrue($mail->hasTo($payload['email']));
        $this->assertSame(10, $mail->minutes);
        $html = $mail->render();
        $this->assertStringContainsString('Do not share this code', $html);
        $this->assertStringContainsString('Copy this code', $html);
        $this->assertStringContainsString($mail->code, $html);

        return $mail->code;
    }
}
