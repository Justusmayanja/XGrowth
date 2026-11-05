<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\YouTube\YouTubeOAuthService;
use App\Support\YouTubeOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.youtube.client_id' => '',
            'services.youtube.client_secret' => '',
            'services.youtube.redirect' => '',
            'services.youtube.scopes' => YouTubeOAuth::READONLY_SCOPE.' '.YouTubeOAuth::ANALYTICS_READONLY_SCOPE,
            'services.frontend.url' => 'http://localhost:3000',
        ]);
        Http::preventStrayRequests();
    }

    public function test_connect_requires_complete_configuration_and_authentication(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/connected-accounts/youtube/connect')->assertUnauthorized();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/connected-accounts/youtube/connect')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'youtube_not_configured');
        Http::assertNothingSent();
    }

    public function test_authorization_is_read_only_and_uses_the_absolute_callback(): void
    {
        $this->configure();
        config(['services.youtube.scopes' => YouTubeOAuth::READONLY_SCOPE.' https://www.googleapis.com/auth/youtube.upload']);
        $user = User::factory()->create();
        $url = app(YouTubeOAuthService::class)->authorizationUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(YouTubeOAuth::AUTHORIZE_URL, strtok($url, '?'));
        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('http://127.0.0.1:8000/api/auth/youtube/callback', $query['redirect_uri']);
        $this->assertSame(YouTubeOAuth::READONLY_SCOPE, $query['scope']);
        $this->assertStringNotContainsString('youtube.upload', $url);
        $this->assertStringNotContainsString('test-secret', $url);
        $this->assertSame($user->id, Cache::get('youtube-oauth-state.'.$query['state'])['user_id']);
    }

    public function test_callback_stores_channel_identity_and_encrypted_tokens(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $state = $this->stateFor($user);
        $this->fakeConnection();

        $this->get('/api/auth/youtube/callback?state='.$state.'&code=channel-code')
            ->assertRedirect('http://localhost:3000/settings?youtube=connected');

        $raw = DB::table('connected_accounts')->first();
        $this->assertSame('youtube', $raw->platform);
        $this->assertSame('UC-123', $raw->platform_account_id);
        $this->assertSame('Kitchen Channel', $raw->username);
        $this->assertStringNotContainsString('access-plain', (string) $raw->access_token);
        $this->assertStringNotContainsString('refresh-plain', (string) $raw->refresh_token);
        $account = ConnectedAccount::query()->firstOrFail();
        $this->assertSame('access-plain', $account->access_token);
        $this->assertSame('refresh-plain', $account->refresh_token);
        $this->assertTrue($account->is_active);
        $this->assertStringNotContainsString('youtube.upload', (string) $account->scopes);
    }

    public function test_connected_metrics_are_real_and_a_failure_never_returns_samples(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $this->link($user);
        Http::fake([
            YouTubeOAuth::CHANNELS_URL.'*' => Http::sequence()
                ->push($this->channelBody())
                ->push(['error' => ['message' => 'quota']], 403),
            YouTubeOAuth::ANALYTICS_URL.'*' => Http::response([
                'columnHeaders' => [],
                'rows' => [[1200, 765]],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/connected-accounts/youtube/status')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.metrics.subscribers', 321)
            ->assertJsonPath('data.metrics.views', 765)
            ->assertJsonPath('data.metrics.watch_hours', 20);

        $desk = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/youtube')
            ->assertOk()
            ->assertJsonPath('preview', false)
            ->assertJsonPath('metrics.0.value', null)
            ->assertJsonPath('metrics.1.value', null)
            ->assertJsonPath('metrics.2.value', null);
        $this->assertNotNull($desk->json('metrics_error'));
    }

    public function test_disconnect_deletes_locally_when_revoke_fails_and_is_user_scoped(): void
    {
        $this->configure();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->link($owner);
        Http::fake([
            YouTubeOAuth::REVOKE_URL => Http::response(['error' => 'server_error'], 500),
        ]);

        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/connected-accounts/youtube')
            ->assertOk();
        $this->assertDatabaseHas('connected_accounts', ['id' => $account->id]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/connected-accounts/youtube')
            ->assertOk()
            ->assertJsonPath('data.connected', false);
        $this->assertDatabaseMissing('connected_accounts', ['id' => $account->id]);
    }

    public function test_denied_and_expired_callbacks_do_not_call_google(): void
    {
        $this->configure();
        Http::fake();
        $user = User::factory()->create();
        $state = $this->stateFor($user);

        $this->get('/api/auth/youtube/callback?state='.$state.'&error=access_denied')
            ->assertRedirect('http://localhost:3000/settings?youtube=error&reason=denied');
        $this->get('/api/auth/youtube/callback?state='.$state.'&code=again')
            ->assertRedirect('http://localhost:3000/settings?youtube=error&reason=expired');
        Http::assertNothingSent();
    }

    private function configure(): void
    {
        config([
            'services.youtube.client_id' => 'test-client',
            'services.youtube.client_secret' => 'test-secret',
            'services.youtube.redirect' => 'http://127.0.0.1:8000/api/auth/youtube/callback',
        ]);
    }

    private function stateFor(User $user): string
    {
        $url = app(YouTubeOAuthService::class)->authorizationUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function fakeConnection(): void
    {
        Http::fake([
            YouTubeOAuth::TOKEN_URL => Http::response([
                'access_token' => 'access-plain',
                'refresh_token' => 'refresh-plain',
                'expires_in' => 3600,
                'scope' => YouTubeOAuth::READONLY_SCOPE.' '.YouTubeOAuth::ANALYTICS_READONLY_SCOPE,
            ]),
            YouTubeOAuth::CHANNELS_URL.'*' => Http::response($this->channelBody()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelBody(): array
    {
        return [
            'items' => [[
                'id' => 'UC-123',
                'snippet' => ['title' => 'Kitchen Channel'],
                'statistics' => ['subscriberCount' => '321', 'viewCount' => '9876'],
            ]],
        ];
    }

    private function link(User $user): ConnectedAccount
    {
        return ConnectedAccount::query()->create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => 'UC-123',
            'username' => 'Kitchen Channel',
            'access_token' => 'access-plain',
            'refresh_token' => 'refresh-plain',
            'token_expires_at' => now()->addHour(),
            'scopes' => YouTubeOAuth::READONLY_SCOPE.' '.YouTubeOAuth::ANALYTICS_READONLY_SCOPE,
            'is_active' => true,
        ]);
    }
}
