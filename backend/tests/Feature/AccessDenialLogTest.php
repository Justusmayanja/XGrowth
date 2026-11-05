<?php

namespace Tests\Feature;

use App\Http\Middleware\LogAccessDenial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessDenialLogTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $denials = [];

    protected function setUp(): void
    {
        parent::setUp();

        Log::listen(function (MessageLogged $event) {
            if ($event->message === 'access_denied') {
                $this->denials[] = $event->context;
            }
        });
    }

    public function test_a_rejected_request_is_logged_without_the_code_or_token(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        $this->getJson('/api/auth/me?code=654321&token=secret-token', [
            'Authorization' => 'Bearer secret-token',
        ])->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertSame([
            'status' => 401,
            'method' => 'GET',
            'path' => '/api/auth/me',
            'user_id' => null,
            'ip' => '203.0.113.10',
        ], $this->onlyDenial());
    }

    public function test_a_blocked_account_keeps_the_same_response_and_is_logged(): void
    {
        $user = User::factory()->create(['is_blocked' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Your account has been suspended. Contact support if you believe this is an error.',
                'blocked' => true,
            ]);

        $this->assertSame([
            'status' => 403,
            'method' => 'GET',
            'path' => '/api/auth/me',
            'user_id' => $user->id,
            'ip' => '127.0.0.1',
        ], $this->onlyDenial());
    }

    public function test_a_normal_request_is_not_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->assertSame([], $this->denials);
    }

    public function test_login_validation_errors_are_not_logged(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'is_blocked' => true,
        ]);

        $this->postJson('/api/auth/email/send', [
            'purpose' => 'login',
            'email' => 'ada@example.com',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This account has been suspended. Contact support for help.');

        $this->assertSame([], $this->denials);
    }

    public function test_repeated_verify_attempts_are_logged_once_they_are_turned_away(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson('/api/auth/email/verify', [
                'email' => 'ada@example.com',
                'code' => '000000',
            ])->assertStatus(422);
        }

        $this->assertSame([], $this->denials);

        $this->postJson('/api/auth/email/verify', [
            'email' => 'ada@example.com',
            'code' => '000000',
        ])->assertTooManyRequests()
            ->assertJsonPath('message', 'Too Many Attempts.');

        $denial = $this->onlyDenial();
        $this->assertSame(429, $denial['status']);
        $this->assertSame('POST', $denial['method']);
        $this->assertSame('/api/auth/email/verify', $denial['path']);
        $this->assertNull($denial['user_id']);
        $this->assertSame('203.0.113.20', $denial['ip']);
        $this->assertStringNotContainsString('000000', (string) json_encode($denial));
    }

    public function test_payment_webhooks_and_sign_in_callbacks_keep_their_current_checks(): void
    {
        foreach ([
            'api/billing/webhooks/paddle',
            'api/billing/webhooks/flutterwave',
            'api/auth/x/callback',
            'api/auth/tiktok/callback',
            'api/auth/youtube/callback',
        ] as $uri) {
            $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route, $uri);

            $middleware = $this->expandedMiddleware($route->gatherMiddleware());
            $this->assertContains(LogAccessDenial::class, $middleware);
            $this->assertFalse(
                collect($middleware)->contains(fn ($name) => str_starts_with((string) $name, 'throttle:')),
                $uri,
            );
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30']);

        $this->postJson('/api/billing/webhooks/paddle', [
            'secret' => 'paddle-body-secret',
        ], ['Paddle-Signature' => 'not-a-real-signature'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid signature.']);

        $denial = $this->onlyDenial();
        $this->assertSame(401, $denial['status']);
        $this->assertSame('/api/billing/webhooks/paddle', $denial['path']);
        $this->assertStringNotContainsString('paddle-body-secret', (string) json_encode($denial));
        $this->assertStringNotContainsString('not-a-real-signature', (string) json_encode($denial));
    }

    /**
     * @param  list<string>  $middleware
     * @return list<string>
     */
    private function expandedMiddleware(array $middleware): array
    {
        $groups = Route::getMiddlewareGroups();
        $expanded = [];

        foreach ($middleware as $name) {
            if (isset($groups[$name])) {
                array_push($expanded, ...$groups[$name]);

                continue;
            }

            $expanded[] = $name;
        }

        return $expanded;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyDenial(): array
    {
        $this->assertCount(1, $this->denials);

        return $this->denials[0];
    }
}
