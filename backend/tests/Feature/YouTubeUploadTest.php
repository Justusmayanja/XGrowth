<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionLine;
use App\Models\ConnectedAccount;
use App\Models\PlatformSetup;
use App\Models\User;
use App\Models\YouTubeInsertAttempt;
use App\Models\YouTubeVideo;
use App\Services\YouTube\YouTubeAllowanceService;
use App\Services\YouTube\YouTubeOAuthService;
use App\Support\YouTubeOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class YouTubeUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $httpState = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.youtube.upload_audit_approved' => false,
            'services.youtube.upload_daily_budget' => 100,
            'services.youtube.client_id' => 'test-client',
            'services.youtube.client_secret' => 'test-secret',
            'services.youtube.redirect' => 'http://127.0.0.1:8000/api/auth/youtube/callback',
            'services.youtube.scopes' => YouTubeOAuth::READONLY_SCOPE.' '.YouTubeOAuth::ANALYTICS_READONLY_SCOPE,
            'services.frontend.url' => 'http://localhost:3000',
        ]);
        Storage::fake('youtube_media');
        Http::preventStrayRequests();
    }

    public function test_a_ready_private_insert_posts_only_the_returned_id_and_privacy(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 4, 'starter');
        $this->connect($user, true);
        $video = $this->readyVideo($user);
        $this->fakeGoogle();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload', ['privacy' => 'private'])
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'posted')
            ->assertJsonPath('pipeline.0.posted_video_id', 'abcdefghijk')
            ->assertJsonPath('pipeline.0.posted_privacy', 'private')
            ->assertJsonPath('pipeline.0.package.upload.youtube_video_id', 'abcdefghijk');

        $video->refresh();
        $this->assertNotNull($video->posted_at);
        $this->assertSame('private', $this->httpState['privacy']);
        $this->assertStringNotContainsString('public', (string) $this->httpState['body']);
        $this->assertStringNotContainsString('unlisted', (string) $this->httpState['body']);
        $this->assertSame(1, $this->httpState['inserts']);
        $this->assertSame(1, YouTubeInsertAttempt::query()->count());
        $this->assertSame('abcdefghijk', $this->httpState['caption_video_id']);
    }

    public function test_public_and_unlisted_are_held_locally_while_the_audit_is_off(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 12, 'pro');
        $this->connect($user, true);
        $video = $this->readyVideo($user);

        foreach (['public', 'unlisted'] as $privacy) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/youtube/videos/'.$video->id.'/upload', ['privacy' => $privacy])
                ->assertStatus(422);

            $video->refresh();
            $this->assertSame('queued', $video->status);
            $this->assertNull($video->posted_video_id);
            $this->assertNull($video->posted_privacy);
            $this->assertSame('held', $video->package['upload']['state']);
            $this->assertFalse($video->package['upload']['retryable']);
            $this->assertStringContainsString('stays on this server', $video->package['upload']['note']);
            $this->assertStringContainsString('not requested', $video->package['upload']['note']);
        }

        Http::assertNothingSent();
        $this->assertSame(0, YouTubeInsertAttempt::query()->count());
    }

    public function test_a_non_private_response_is_not_marked_posted_while_unaudited(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 4, 'starter');
        $this->connect($user, true);
        $video = $this->readyVideo($user);
        $this->fakeGoogle(['applied' => 'public']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertStatus(422);

        $video->refresh();
        $this->assertSame('private', $this->httpState['privacy']);
        $this->assertSame('queued', $video->status);
        $this->assertNull($video->posted_video_id);
        $this->assertNull($video->posted_privacy);
        $this->assertFalse($video->package['upload']['retryable']);
        $this->assertSame(0, $this->httpState['captions']);
    }

    public function test_insert_without_an_id_or_privacy_stays_unsent_and_can_be_retried(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 4, 'starter');
        $this->connect($user, true);
        $video = $this->readyVideo($user);
        $this->fakeGoogle(['omit_id' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertStatus(422);

        $video->refresh();
        $this->assertSame('queued', $video->status);
        $this->assertNull($video->posted_video_id);
        $this->assertTrue($video->package['upload']['retryable']);
        $this->assertSame(0, $this->httpState['captions']);

        $this->fakeGoogle(['omit_privacy' => true]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertStatus(422);
        $video->refresh();
        $this->assertNull($video->posted_video_id);
        $this->assertSame('queued', $video->status);
    }

    public function test_a_failed_insert_stays_queued_and_a_later_attempt_can_post(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 4, 'starter');
        $this->connect($user, true);
        $video = $this->readyVideo($user);
        $this->fakeGoogle(['put_fails_once' => true, 'no_captions' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertStatus(422);

        $video->refresh();
        $this->assertSame('queued', $video->status);
        $this->assertNull($video->posted_video_id);
        $this->assertTrue($video->package['upload']['retryable']);
        $this->assertSame(0, $this->httpState['captions']);

        $this->fakeGoogle(['no_captions' => true]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'posted')
            ->assertJsonPath('pipeline.0.posted_privacy', 'private');

        $this->assertSame(2, YouTubeInsertAttempt::query()->count());
        $this->assertSame(1, app(YouTubeAllowanceService::class)->status($user)['used']);
    }

    public function test_resumable_upload_continues_after_an_incomplete_chunk(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 4, 'starter');
        $this->connect($user, true);
        $video = $this->readyVideo($user, contents: 'abcd');
        $this->fakeGoogle(['incomplete_once' => true, 'no_captions' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertOk();

        $this->assertSame(2, $this->httpState['puts']);
        $this->assertSame('bytes 0-3/4', $this->httpState['ranges'][0]);
        $this->assertSame('bytes 1-3/4', $this->httpState['ranges'][1]);
    }

    public function test_captions_list_runs_only_after_a_video_id_and_paste_survives_failure(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 4, 'starter');
        $this->connect($user, true);
        $video = $this->readyVideo($user);
        $paste = (string) $video->package['transcript'];
        $this->fakeGoogle(['captions_fail' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertOk();

        $video->refresh();
        $this->assertSame('posted', $video->status);
        $this->assertSame($paste, $video->package['transcript']);
        $this->assertSame('abcdefghijk', $this->httpState['caption_video_id']);
        $this->assertSame(1, $this->httpState['captions']);

        $second = $this->readyVideo($user);
        $this->fakeGoogle(['srt' => $this->srt(), 'no_captions' => false]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$second->id.'/upload')
            ->assertOk();

        $second->refresh();
        $this->assertSame('posted', $second->status);
        $this->assertStringContainsString('-->', (string) $second->package['transcript']);
        $this->assertNotEmpty($second->package['shorts']);
        $this->assertNotSame($paste, $second->package['transcript']);
    }

    public function test_missing_file_scope_or_connection_never_calls_google(): void
    {
        $user = User::factory()->create();
        $this->allowance($user, 4, 'starter');
        $notReady = $this->readyVideo($user, ready: false);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$notReady->id.'/upload')
            ->assertStatus(422);
        $notReady->refresh();
        $this->assertSame('queued', $notReady->status);
        $this->assertStringContainsString('not ready', $notReady->package['upload']['note']);

        $missing = $this->readyVideo($user, file: false);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$missing->id.'/upload')
            ->assertStatus(422);
        $missing->refresh();
        $this->assertStringContainsString('missing', $missing->package['upload']['note']);

        $this->connect($user, false);
        $readonly = $this->readyVideo($user);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$readonly->id.'/upload')
            ->assertStatus(422);
        $readonly->refresh();
        $this->assertStringContainsString('read-only', $readonly->package['upload']['note']);

        ConnectedAccount::query()->where('user_id', $user->id)->update(['is_active' => false]);
        $inactive = $this->readyVideo($user);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$inactive->id.'/upload')
            ->assertStatus(422);

        $waiting = $this->readyVideo($user);
        $waiting->update(['status' => 'waiting']);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/youtube/videos/'.$waiting->id.'/upload')
            ->assertStatus(422);
        $waiting->refresh();
        $this->assertSame('waiting', $waiting->status);
        $this->assertStringContainsString('Ship it approves', $waiting->package['upload']['note']);

        Http::assertNothingSent();
        $this->assertSame(0, YouTubeInsertAttempt::query()->count());
    }

    public function test_monthly_allowance_is_independent_of_the_shared_daily_budget(): void
    {
        config(['services.youtube.upload_daily_budget' => 100]);
        Http::preventStrayRequests();

        foreach (config('billing.platforms.youtube.tiers') as $tier => $spec) {
            $limit = (int) $spec['allowance'];
            $user = User::factory()->create();
            $this->allowance($user, $limit, (string) $tier);
            $this->postedRows($user, $limit);
            $this->connect($user, true);
            $video = $this->readyVideo($user);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/youtube/videos/'.$video->id.'/upload')
                ->assertStatus(422);

            $message = (string) $response->json('errors.upload.0');
            $this->assertStringContainsString('used all '.$limit, $message);
            $this->assertStringNotContainsString('shared upload budget', $message);
            $video->refresh();
            $this->assertSame('queued', $video->status);
            $this->assertNull($video->posted_video_id);
        }

        $this->assertSame(0, YouTubeInsertAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_spent_daily_budget_keeps_the_next_video_queued_for_tomorrow(): void
    {
        config([
            'services.youtube.upload_daily_budget' => 1,
            'services.youtube.upload_audit_approved' => true,
        ]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->allowance($first, 40, 'agency');
        $this->allowance($second, 40, 'agency');
        $this->connect($first, true);
        $this->connect($second, true);
        $ready = $this->readyVideo($first);
        $held = $this->readyVideo($second);
        $this->fakeGoogle(['applied' => 'public', 'no_captions' => true]);

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/youtube/videos/'.$ready->id.'/upload', ['privacy' => 'public'])
            ->assertOk()
            ->assertJsonPath('pipeline.0.status', 'posted')
            ->assertJsonPath('pipeline.0.posted_privacy', 'public');

        $this->assertSame(39, app(YouTubeAllowanceService::class)->status($first)['remaining']);
        $this->assertSame(40, app(YouTubeAllowanceService::class)->status($second)['remaining']);

        $response = $this->actingAs($second, 'sanctum')
            ->postJson('/api/youtube/videos/'.$held->id.'/upload', ['privacy' => 'private'])
            ->assertStatus(422);

        $message = (string) $response->json('errors.upload.0');
        $held->refresh();
        $this->assertStringContainsString('shared upload budget', $message);
        $this->assertStringContainsString(now()->utc()->addDay()->toDateString(), $message);
        $this->assertStringNotContainsString('used all', $message);
        $this->assertSame('queued', $held->status);
        $this->assertNull($held->posted_video_id);
        $this->assertTrue($held->package['upload']['retryable']);
        $this->assertSame(now()->utc()->addDay()->toDateString(), $held->package['upload']['next_attempt_on']);
        $this->assertSame(1, $this->httpState['inserts']);
        $this->assertSame(1, YouTubeInsertAttempt::query()->count());
        $this->assertSame('public', $this->httpState['privacy']);
    }

    public function test_upload_consent_is_separate_from_the_read_only_grant(): void
    {
        config(['services.youtube.scopes' => YouTubeOAuth::READONLY_SCOPE.' '.YouTubeOAuth::UPLOAD_SCOPE]);
        $user = User::factory()->create();
        $readOnly = app(YouTubeOAuthService::class)->authorizationUrl($user);
        $this->assertStringNotContainsString('youtube.upload', $readOnly);
        $this->assertStringContainsString(urlencode(YouTubeOAuth::READONLY_SCOPE), $readOnly);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/connected-accounts/youtube/upload-consent')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'youtube_channel_required');

        $account = $this->connect($user, false);
        $url = $this->actingAs($user, 'sanctum')
            ->postJson('/api/connected-accounts/youtube/upload-consent')
            ->assertOk()
            ->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(YouTubeOAuth::UPLOAD_SCOPE, $query['scope']);
        $this->assertStringNotContainsString('youtube.readonly', (string) $query['scope']);
        $this->assertSame('true', $query['include_granted_scopes']);

        Http::fake([
            YouTubeOAuth::TOKEN_URL => Http::response([
                'access_token' => 'upload-access',
                'refresh_token' => 'upload-refresh',
                'expires_in' => 3600,
                'scope' => YouTubeOAuth::UPLOAD_SCOPE,
            ]),
            YouTubeOAuth::CHANNELS_URL.'*' => Http::response([
                'items' => [[
                    'id' => $account->platform_account_id,
                    'snippet' => ['title' => 'Kitchen'],
                    'statistics' => ['subscriberCount' => '1'],
                ]],
            ]),
        ]);

        $this->get('/api/auth/youtube/callback?state='.$query['state'].'&code=upload-code')
            ->assertRedirect('http://localhost:3000/settings?youtube=upload');

        $account->refresh();
        $this->assertStringContainsString(YouTubeOAuth::UPLOAD_SCOPE, (string) $account->scopes);
        $this->assertStringContainsString(YouTubeOAuth::READONLY_SCOPE, (string) $account->scopes);
        $this->assertSame('upload-access', $account->access_token);
        $this->assertSame(1, ConnectedAccount::query()->where('user_id', $user->id)->count());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/connected-accounts/youtube/status')
            ->assertOk()
            ->assertJsonPath('data.upload_granted', true)
            ->assertJsonPath('data.needs_upload_consent', false);
    }

    public function test_a_refused_upload_consent_leaves_the_read_only_grant_alone(): void
    {
        $user = User::factory()->create();
        $account = $this->connect($user, false);
        $before = $account->scopes;
        $url = $this->actingAs($user, 'sanctum')
            ->postJson('/api/connected-accounts/youtube/upload-consent')
            ->assertOk()
            ->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        Http::preventStrayRequests();

        $this->get('/api/auth/youtube/callback?state='.$query['state'].'&error=access_denied')
            ->assertRedirect('http://localhost:3000/settings?youtube=error&reason=upload_denied');

        $account->refresh();
        $this->assertSame($before, $account->scopes);
        $this->assertSame('access-plain', $account->access_token);
        Http::assertNothingSent();
    }

    public function test_upload_is_refused_for_another_user_and_for_a_guest(): void
    {
        $owner = User::factory()->create();
        $video = $this->readyVideo($owner);
        $this->postJson('/api/youtube/videos/'.$video->id.'/upload')->assertUnauthorized();

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/youtube/videos/'.$video->id.'/upload')
            ->assertNotFound();

        $video->refresh();
        $this->assertSame('queued', $video->status);
        $this->assertArrayNotHasKey('upload', $video->package);
        Http::assertNothingSent();
    }

    public function test_youtube_run_does_not_insert(): void
    {
        $user = User::factory()->create();
        $this->readyVideo($user);
        $this->actingAs($user, 'sanctum')->postJson('/api/youtube/run')->assertOk();
        Http::assertNothingSent();
        $this->assertSame(0, YouTubeInsertAttempt::query()->count());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function fakeGoogle(array $options = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->httpState = [
            'inserts' => 0,
            'puts' => 0,
            'captions' => 0,
            'privacy' => null,
            'body' => '',
            'caption_video_id' => null,
            'ranges' => [],
        ];
        Http::fake(function (Request $request) use ($options) {
            $url = $request->url();
            if (str_contains($url, 'uploadType=resumable') && $request->method() === 'POST') {
                $this->httpState['inserts']++;
                $this->httpState['privacy'] = $request->data()['status']['privacyStatus'] ?? null;
                $this->httpState['body'] = (string) json_encode($request->data());

                return Http::response('', 200, ['Location' => 'https://upload.example.test/session']);
            }
            if (str_starts_with($url, 'https://upload.example.test/session') && $request->method() === 'PUT') {
                $this->httpState['puts']++;
                $headers = $request->headers();
                $range = $headers['Content-Range'][0] ?? $headers['content-range'][0] ?? '';
                $this->httpState['ranges'][] = $range;
                if (! empty($options['incomplete_once']) && $this->httpState['puts'] === 1) {
                    return Http::response('', 308, ['Range' => 'bytes=0-0']);
                }
                if (! empty($options['put_fails_once']) && $this->httpState['puts'] === 1) {
                    return Http::response(['error' => 'no'], 500);
                }
                if (! empty($options['omit_id'])) {
                    return Http::response(['status' => ['privacyStatus' => 'private']]);
                }
                if (! empty($options['omit_privacy'])) {
                    return Http::response(['id' => 'abcdefghijk']);
                }

                return Http::response([
                    'id' => 'abcdefghijk',
                    'status' => ['privacyStatus' => $options['applied'] ?? 'private'],
                ]);
            }
            if (str_contains($url, '/captions/')) {
                $this->httpState['captions']++;

                return Http::response($options['srt'] ?? "1\n00:00:00,000 --> 00:00:02,000\nHello.\n");
            }
            if (str_contains($url, '/captions')) {
                $this->httpState['captions']++;
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $this->httpState['caption_video_id'] = $query['videoId'] ?? null;
                if (! empty($options['captions_fail'])) {
                    return Http::response(['error' => 'forbidden'], 403);
                }
                if (! empty($options['no_captions'])) {
                    return Http::response(['items' => []]);
                }

                return Http::response(['items' => [['id' => 'cap-1']]]);
            }

            throw new \RuntimeException('Unexpected Google call '.$request->method().' '.$url);
        });
    }

    private function readyVideo(User $user, bool $ready = true, bool $file = true, string $contents = 'abcd'): YouTubeVideo
    {
        $this->profile($user);
        $video = YouTubeVideo::query()->create([
            'user_id' => $user->id,
            'mode' => 'short',
            'format' => 'text_on_screen',
            'status' => 'queued',
            'title' => 'Cheap weeknight soup',
            'description' => 'A short description.',
            'queued_at' => now(),
            'package' => [
                'tags' => ['soup'],
                'transcript' => "0:00 Keep this paste.\n0:04 Still here.\n",
                'render' => [
                    'provider' => $ready ? 'ffmpeg' : 'pending',
                    'ready' => $ready,
                    'path' => 'pending',
                    'extension' => 'mp4',
                ],
            ],
        ]);
        $path = $user->id.'/'.$video->id.'/render.mp4';
        $package = $video->package;
        $package['render']['path'] = $path;
        $video->update(['package' => $package]);
        if ($ready && $file) {
            Storage::disk('youtube_media')->put($path, $contents);
        }

        return $video->fresh() ?? $video;
    }

    private function profile(User $user): void
    {
        AccountProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'purpose' => 'creator',
                'platforms' => 'youtube',
                'custom_niche' => 'Budget home cooking',
                'description' => 'I cook cheap weeknight meals and share the shortcuts that actually finish.',
                'audience' => 'Students cooking for one',
                'tone' => 'funny',
                'onboarding_completed' => true,
            ],
        );
        PlatformSetup::query()->firstOrCreate(
            ['user_id' => $user->id, 'platform' => 'youtube'],
            [
                'goals' => 'subscribers',
                'settings' => ['publish' => 'wait', 'video_mode' => 'short', 'format' => 'text_on_screen'],
                'completed_at' => now(),
            ],
        );
    }

    private function connect(User $user, bool $withUpload): ConnectedAccount
    {
        $scopes = YouTubeOAuth::READONLY_SCOPE.' '.YouTubeOAuth::ANALYTICS_READONLY_SCOPE;
        if ($withUpload) {
            $scopes .= ' '.YouTubeOAuth::UPLOAD_SCOPE;
        }

        return ConnectedAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => 'youtube',
                'platform_account_id' => 'UC-'.$user->id,
            ],
            [
                'username' => 'Kitchen',
                'access_token' => 'access-plain',
                'refresh_token' => 'refresh-plain',
                'token_expires_at' => now()->addHours(2),
                'is_active' => true,
                'scopes' => $scopes,
            ],
        );
    }

    private function allowance(User $user, int $limit, string $tier): void
    {
        $start = now()->utc()->subDay();
        $end = now()->utc()->addMonth();
        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'interval' => 'monthly',
            'currency' => 'USD',
            'subtotal_cents' => 6900,
            'discount_cents' => 0,
            'total_cents' => 6900,
            'activated_at' => $start,
            'current_period_starts_at' => $start,
            'current_period_ends_at' => $end,
            'renews' => true,
        ]);
        BillingSubscriptionLine::query()->create([
            'billing_subscription_id' => $subscription->id,
            'platform' => 'youtube',
            'tier' => $tier,
            'list_cents' => 6900,
            'charged_cents' => 6900,
            'discount_cents' => 0,
            'is_anchor' => true,
            'accounts_included' => 1,
            'monthly_allowance' => $limit,
            'allowance_unit' => 'videos',
        ]);
    }

    private function postedRows(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            YouTubeVideo::query()->create([
                'user_id' => $user->id,
                'mode' => 'short',
                'status' => 'posted',
                'title' => 'Posted '.$i,
                'posted_at' => now(),
                'posted_video_id' => sprintf('posted%02dxxxx', $i),
                'posted_privacy' => 'private',
                'package' => [],
            ]);
        }
    }

    private function srt(): string
    {
        $lines = [
            [0, 8, 'Cheap weeknight meals finish when the shortcut is the meal itself.'],
            [8, 16, 'The shop is the part that feels like work.'],
            [16, 28, 'But the pan you already own is the part that finishes.'],
            [28, 40, 'That is the shortcut, and the plate is the proof.'],
            [40, 52, 'A second sitting is the same pan, not a new plan.'],
        ];
        $blocks = [];
        foreach ($lines as $index => [$start, $end, $text]) {
            $blocks[] = ($index + 1)."\n".$this->stamp($start).' --> '.$this->stamp($end)."\n".$text;
        }

        return implode("\n\n", $blocks)."\n";
    }

    private function stamp(int $seconds): string
    {
        return sprintf('00:%02d:%02d,000', intdiv($seconds, 60), $seconds % 60);
    }
}
