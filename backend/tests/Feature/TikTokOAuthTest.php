<?php

namespace Tests\Feature;

use App\Exceptions\TikTokOAuthException;
use App\Models\ConnectedAccount;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\TikTok\TikTokOAuthService;
use App\Support\TikTokCatalog;
use App\Support\TikTokOAuth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class TikTokOAuthTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.tiktok.client_key' => '',
            'services.tiktok.client_secret' => '',
            'services.tiktok.redirect' => '',
            'services.tiktok.scopes' => 'user.info.basic',
            'services.frontend.url' => 'http://localhost:3000',
        ]);
        Http::preventStrayRequests();
    }

    public function test_connect_is_unavailable_until_the_keys_exist(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->assertFalse(app(TikTokOAuthService::class)->isConfigured());
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/connected-accounts/tiktok/connect')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'tiktok_not_configured')
            ->assertJsonPath('error.message', "TikTok linking isn't available yet. It's waiting on TikTok's approval.");
        Http::assertNothingSent();
    }

    public function test_the_authorization_url_keeps_secrets_in_cache(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $url = app(TikTokOAuthService::class)->authorizationUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $cached = Cache::get('tiktok-oauth-state.'.$query['state']);

        $this->assertSame(TikTokOAuth::AUTHORIZE_URL, strtok($url, '?'));
        $this->assertSame('test-key', $query['client_key']);
        $this->assertSame('user.info.basic', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('http://127.0.0.1:8000/api/auth/tiktok/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertGreaterThanOrEqual(40, strlen($query['state']));
        $this->assertSame(hash('sha256', $cached['verifier']), $query['code_challenge']);
        $this->assertNotSame($this->base64Url(hash('sha256', $cached['verifier'], true)), $query['code_challenge']);
        $this->assertSame($user->id, $cached['user_id']);
        $this->assertStringNotContainsString('test-secret', $url);
        $this->assertStringNotContainsString($cached['verifier'], $url);
        $this->assertStringNotContainsString('video.upload', $url);
        $this->assertStringNotContainsString('video.publish', $url);

        $this->travel(11)->minutes();
        $this->assertNull(Cache::get('tiktok-oauth-state.'.$query['state']));
    }

    public function test_callback_stores_encrypted_tokens_and_redirects(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $this->captureLogs();
        $state = $this->stateFor($user);
        $this->fakeTikTok();

        $this->get('/api/auth/tiktok/callback?state='.$state.'&code=auth-code-value')
            ->assertRedirect('http://localhost:3000/settings?tiktok=connected');

        $row = DB::table('connected_accounts')->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('tiktok', $row->platform);
        $this->assertSame('oid-1', $row->platform_account_id);
        $this->assertSame('Kitchen', $row->username);
        $this->assertNotSame('act-plain', $row->access_token);
        $this->assertStringNotContainsString('act-plain', (string) $row->access_token);
        $this->assertStringNotContainsString('rft-plain', (string) $row->refresh_token);
        $account = ConnectedAccount::query()->find($row->id);
        $this->assertSame('act-plain', $account->access_token);
        $this->assertTrue($account->is_active);

        $this->get('/api/auth/tiktok/callback?state='.$state.'&code=auth-code-value')
            ->assertRedirect('http://localhost:3000/settings?tiktok=error&reason=expired');
        Http::assertSentCount(2);
        $this->assertSecretsStayOut($this->logs, ['act-plain', 'rft-plain', 'auth-code-value', 'test-secret']);
    }

    public function test_unknown_expired_and_denied_callbacks_make_no_request(): void
    {
        $this->configure();
        Http::fake();
        $user = User::factory()->create();

        $this->get('/api/auth/tiktok/callback?state=not-a-real-state-value-with-forty-plus-chars')
            ->assertRedirect('http://localhost:3000/settings?tiktok=error&reason=expired');

        $state = $this->stateFor($user);
        $this->get('/api/auth/tiktok/callback?state='.$state.'&error=access_denied')
            ->assertRedirect('http://localhost:3000/settings?tiktok=error&reason=denied');
        $this->get('/api/auth/tiktok/callback?state='.$state.'&code=again')
            ->assertRedirect('http://localhost:3000/settings?tiktok=error&reason=expired');

        Http::assertNothingSent();
        $this->assertSame(0, ConnectedAccount::query()->count());
    }

    public function test_a_failed_exchange_or_profile_leaves_no_row(): void
    {
        $this->configure();
        $user = User::factory()->create();

        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::response(['error' => 'invalid_request'], 400),
        ]);
        $this->get('/api/auth/tiktok/callback?state='.$this->stateFor($user).'&code=auth-code-value')
            ->assertRedirect('http://localhost:3000/settings?tiktok=error&reason=failed');

        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::response($this->tokenBody(), 200),
            TikTokOAuth::USER_INFO_URL.'*' => Http::response(['error' => ['code' => 'access_token_invalid']], 401),
        ]);
        $this->get('/api/auth/tiktok/callback?state='.$this->stateFor($user).'&code=auth-code-value')
            ->assertRedirect('http://localhost:3000/settings?tiktok=error&reason=failed');

        $this->assertSame(0, ConnectedAccount::query()->count());
    }

    public function test_a_tiktok_account_cannot_move_to_another_user(): void
    {
        $this->configure();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->link($owner, 'oid-1', 'act-owner');
        $this->fakeTikTok();

        $this->get('/api/auth/tiktok/callback?state='.$this->stateFor($other).'&code=auth-code-value')
            ->assertRedirect('http://localhost:3000/settings?tiktok=error&reason=already_linked');

        $this->assertSame(1, ConnectedAccount::query()->count());
        $this->assertSame('act-owner', ConnectedAccount::query()->where('user_id', $owner->id)->first()->access_token);
        $this->assertNull(ConnectedAccount::query()->where('user_id', $other->id)->first());
    }

    public function test_reconnecting_updates_and_a_different_account_replaces(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $existing = $this->link($user, 'oid-1', 'act-old');
        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::sequence()
                ->push($this->tokenBody('act-new', 'rft-new', 'oid-1'), 200)
                ->push($this->tokenBody('act-other', 'rft-other', 'oid-2'), 200),
            TikTokOAuth::USER_INFO_URL.'*' => Http::sequence()
                ->push([
                    'data' => ['user' => ['open_id' => 'oid-1', 'display_name' => 'Kitchen']],
                    'error' => ['code' => 'ok'],
                ], 200)
                ->push([
                    'data' => ['user' => ['open_id' => 'oid-2', 'display_name' => 'Other']],
                    'error' => ['code' => 'ok'],
                ], 200),
        ]);

        app(TikTokOAuthService::class)->handleCallback($this->stateFor($user), 'auth-code-value', null);
        $this->assertSame(1, ConnectedAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame($existing->id, ConnectedAccount::query()->where('user_id', $user->id)->first()->id);
        $this->assertSame('act-new', $existing->fresh()->access_token);

        app(TikTokOAuthService::class)->handleCallback($this->stateFor($user), 'auth-code-value', null);

        $rows = ConnectedAccount::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('oid-2', $rows->first()->platform_account_id);
        $this->assertSame('Other', $rows->first()->username);
    }

    public function test_an_invalid_grant_keeps_the_row_and_the_tokens(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $account = $this->link($user, 'oid-1', 'act-old', 'rft-old', now()->addMinutes(5));
        $cipher = DB::table('connected_accounts')->value('access_token');

        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $stale = app(TikTokOAuthService::class)->refresh($account);
        $this->assertFalse($stale->is_active);
        $this->assertSame('act-old', $stale->access_token);
        $this->assertSame($cipher, DB::table('connected_accounts')->value('access_token'));
        Http::assertSentCount(1);
    }

    public function test_a_server_error_is_retried_and_does_not_change_the_stored_token(): void
    {
        $this->configure();
        $account = $this->link(User::factory()->create(), 'oid-1', 'act-old', 'rft-old', now()->addMinutes(5));
        $cipher = DB::table('connected_accounts')->value('access_token');
        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::sequence()
                ->push(['error' => 'unavailable'], 500)
                ->push(['error' => 'unavailable'], 500)
                ->push(['error' => 'unavailable'], 500),
        ]);

        try {
            app(TikTokOAuthService::class)->refresh($account);
            $this->fail('A failed refresh should not look successful.');
        } catch (TikTokOAuthException $e) {
            $this->assertSame('failed', $e->reason);
        }

        $this->assertTrue($account->fresh()->is_active);
        $this->assertSame('act-old', $account->fresh()->access_token);
        $this->assertSame($cipher, DB::table('connected_accounts')->value('access_token'));
        Http::assertSentCount(3);
    }

    public function test_the_refresh_command_skips_later_tokens_and_isolates_failures(): void
    {
        $this->configure();
        $soon = $this->link(User::factory()->create(), 'oid-soon', 'act-soon', 'rft-soon', now()->addHour());
        $later = $this->link(User::factory()->create(), 'oid-later', 'act-later', 'rft-later', now()->addDays(3));
        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::sequence()
                ->push(['error' => 'server_error'], 500)
                ->push(['error' => 'server_error'], 500)
                ->push(['error' => 'server_error'], 500)
                ->push($this->tokenBody('act-fresh', 'rft-fresh', 'oid-later'), 200),
        ]);

        $healthy = $this->link(User::factory()->create(), 'oid-ok', 'act-ok', 'rft-ok', now()->addHour());
        $this->artisan('tiktok:refresh-tokens')->assertSuccessful();

        $this->assertSame('act-soon', $soon->fresh()->access_token);
        $this->assertTrue($soon->fresh()->is_active);
        $this->assertSame('act-fresh', $healthy->fresh()->access_token);
        $this->assertSame('act-later', $later->fresh()->access_token);
        Http::assertSentCount(4);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'tiktok-token-refresh');
        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_ensure_fresh_refreshes_only_a_token_near_expiry(): void
    {
        $this->configure();
        $near = $this->link(User::factory()->create(), 'oid-near', 'act-near', 'rft-near', now()->addMinutes(5));
        $far = $this->link(User::factory()->create(), 'oid-far', 'act-far', 'rft-far', now()->addHours(5));
        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::response($this->tokenBody('act-near-new', 'rft-near-new', 'oid-near'), 200),
        ]);

        app(TikTokOAuthService::class)->ensureFresh($far);
        app(TikTokOAuthService::class)->ensureFresh($near);

        $this->assertSame('act-far', $far->fresh()->access_token);
        $this->assertSame('act-near-new', $near->fresh()->access_token);
        Http::assertSentCount(1);
    }

    public function test_disconnect_survives_a_revoke_failure_and_the_clip_stays_queued(): void
    {
        $this->configure();
        $user = User::factory()->create();
        $this->giveTikTokAllowance($user);
        $this->link($user, 'oid-1', 'act-plain');
        $clip = TikTokClip::factory()->queued()->create(['user_id' => $user->id]);
        Http::fake([
            TikTokOAuth::REVOKE_URL => Http::response(['error' => 'server_error'], 500),
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/connected-accounts/tiktok')
            ->assertOk()
            ->assertJsonPath('data.connected', false);
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/connected-accounts/tiktok')
            ->assertOk();

        $this->assertSame(0, ConnectedAccount::query()->count());
        $this->assertSame(TikTokCatalog::QUEUED, $clip->fresh()->status);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tiktok/clips?status=queued')
            ->assertOk()
            ->assertJsonPath('data.0.blockers', [TikTokCatalog::BLOCKER_VIDEO_FILE, TikTokCatalog::BLOCKER_TIKTOK_CONNECTION]);
    }

    public function test_an_active_link_clears_only_the_connection_blocker(): void
    {
        $user = User::factory()->create();
        $this->link($user, 'oid-1', 'act-plain');
        TikTokClip::factory()->queued()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tiktok/clips?status=queued')
            ->assertOk()
            ->assertJsonPath('data.0.blockers', [TikTokCatalog::BLOCKER_VIDEO_FILE]);

        ConnectedAccount::query()->where('user_id', $user->id)->update(['is_active' => false]);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tiktok/clips?status=queued')
            ->assertJsonPath('data.0.blockers', [TikTokCatalog::BLOCKER_VIDEO_FILE, TikTokCatalog::BLOCKER_TIKTOK_CONNECTION]);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/connected-accounts/tiktok/status')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.needs_reconnect', true);
    }

    public function test_another_users_connection_and_confirm_do_not_leak(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->giveTikTokAllowance($other);
        $this->link($owner, 'oid-1', 'act-secret-value');
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $other->id]);

        $status = $this->actingAs($other, 'sanctum')->getJson('/api/connected-accounts/tiktok/status')->assertOk();
        $list = $this->actingAs($other, 'sanctum')->getJson('/api/connected-accounts')->assertOk();
        $this->assertStringNotContainsString('act-secret-value', $status->getContent());
        $this->assertStringNotContainsString('act-secret-value', $list->getContent());
        $this->assertSame([], $list->json('accounts'));

        $before = ConnectedAccount::query()->count();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
            ->assertOk();
        $this->assertSame($before, ConnectedAccount::query()->count());
        $this->assertSame(TikTokCatalog::QUEUED, $clip->fresh()->status);
    }

    private function configure(): void
    {
        config([
            'services.tiktok.client_key' => 'test-key',
            'services.tiktok.client_secret' => 'test-secret',
            'services.tiktok.redirect' => 'http://127.0.0.1:8000/api/auth/tiktok/callback',
        ]);
    }

    private function stateFor(User $user): string
    {
        $url = app(TikTokOAuthService::class)->authorizationUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function fakeTikTok(string $access = 'act-plain', string $refresh = 'rft-plain', string $openId = 'oid-1'): void
    {
        Http::fake([
            TikTokOAuth::TOKEN_URL => Http::response($this->tokenBody($access, $refresh, $openId), 200),
            TikTokOAuth::USER_INFO_URL.'*' => Http::response([
                'data' => ['user' => ['open_id' => $openId, 'display_name' => 'Kitchen', 'avatar_url' => 'https://cdn.example/a.jpg']],
                'error' => ['code' => 'ok', 'message' => ''],
            ], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenBody(string $access = 'act-plain', string $refresh = 'rft-plain', string $openId = 'oid-1'): array
    {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => 86400,
            'refresh_expires_in' => 31536000,
            'open_id' => $openId,
            'scope' => 'user.info.basic',
            'token_type' => 'Bearer',
        ];
    }

    private function link(User $user, string $openId, string $access, string $refresh = 'rft-old', mixed $expires = null): ConnectedAccount
    {
        return ConnectedAccount::query()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => $openId,
            'username' => 'Kitchen',
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_expires_at' => $expires,
            'is_active' => true,
        ]);
    }

    private function captureLogs(): void
    {
        $this->logs = [];
        Log::listen(function (MessageLogged $event) {
            $this->logs[] = $event->message.' '.json_encode($event->context);
        });
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $secrets
     */
    private function assertSecretsStayOut(array $lines, array $secrets): void
    {
        $blob = implode("\n", $lines);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $blob);
        }
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
