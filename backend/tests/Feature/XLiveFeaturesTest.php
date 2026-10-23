<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\Trend;
use App\Models\User;
use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XLiveFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_status_returns_the_x_authorize_url(): void
    {
        config([
            'services.x.client_id' => 'client',
            'services.x.client_secret' => 'secret',
            'services.x.redirect' => 'https://mackie17.com/api/auth/x/callback',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/connected-accounts/x/status?next=/settings')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('connected', false);

        $this->assertStringStartsWith('https://twitter.com/i/oauth2/authorize?', $response->json('authorize_url'));
    }

    public function test_an_x_draft_is_posted_through_the_api(): void
    {
        config([
            'services.x.client_id' => 'client',
            'services.x.client_secret' => 'secret',
        ]);

        Http::fake([
            'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '99']], 201),
        ]);

        $user = User::factory()->create();
        ConnectedAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => '42',
            'username' => 'mackie',
            'access_token' => 'user-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        $draft = $user->posts()->create([
            'content' => 'A real post, not a template.',
            'content_type' => 'tweet',
            'status' => 'draft',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/content/drafts/{$draft->id}/publish")
            ->assertOk()
            ->assertJsonPath('post.status', 'posted')
            ->assertJsonPath('post.platform_post_id', '99');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
            && $request['text'] === 'A real post, not a template.');
    }

    public function test_the_reply_feed_uses_live_posts_instead_of_templates(): void
    {
        $user = User::factory()->create();
        $user->accountProfile()->create([
            'custom_niche' => 'budget cooking',
            'onboarding_completed' => true,
        ]);
        ConnectedAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => '42',
            'username' => 'mackie',
            'access_token' => 'user-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response([
                'data' => [[
                    'id' => '55',
                    'text' => 'Real post about cooking',
                    'author_id' => '7',
                    'created_at' => now()->subMinutes(5)->toIso8601String(),
                    'public_metrics' => ['like_count' => 3, 'retweet_count' => 1, 'reply_count' => 2],
                ]],
                'includes' => [
                    'users' => [[
                        'id' => '7',
                        'username' => 'cook',
                        'public_metrics' => ['followers_count' => 120],
                    ]],
                ],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/engagement/feed')
            ->assertOk()
            ->assertJsonPath('posts.0.id', '55')
            ->assertJsonPath('posts.0.author', '@cook')
            ->assertJsonMissing(['author' => '@niche_insider']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tweets/search/recent')) {
                return false;
            }

            $query = (string) $request->data()['query'];

            return str_contains($query, 'budget')
                && str_contains($query, 'cooking')
                && ! str_contains($query, 'lang:en');
        });
    }

    public function test_a_real_reply_is_shown_when_the_niche_search_is_empty(): void
    {
        $user = User::factory()->create();
        $user->accountProfile()->create([
            'custom_niche' => 'budget cooking',
            'onboarding_completed' => true,
        ]);
        ConnectedAccount::create([
            'user_id' => $user->id,
            'platform' => 'x',
            'platform_account_id' => '42',
            'username' => 'mackie',
            'access_token' => 'user-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.twitter.com/2/users/42/mentions*' => Http::response([
                'data' => [[
                    'id' => '88',
                    'text' => 'A reply that already exists',
                    'author_id' => '9',
                    'created_at' => now()->subMinutes(2)->toIso8601String(),
                    'public_metrics' => ['like_count' => 1, 'retweet_count' => 0, 'reply_count' => 0],
                ]],
                'includes' => [
                    'users' => [[
                        'id' => '9',
                        'username' => 'reader',
                        'public_metrics' => ['followers_count' => 40],
                    ]],
                ],
            ]),
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/engagement/feed')
            ->assertOk()
            ->assertJsonPath('posts.0.id', '88')
            ->assertJsonPath('posts.0.author', '@reader')
            ->assertJsonPath('posts.0.kind', 'mention')
            ->assertJsonPath('note', null);
    }

    public function test_trends_come_from_x_and_mock_rows_are_removed(): void
    {
        config([
            'services.x.client_id' => 'client',
            'services.x.client_secret' => 'secret',
        ]);

        Trend::create([
            'name' => 'Transfer deadline drama',
            'slug' => 'transfer-deadline-drama',
            'score' => 90,
            'metadata' => ['source' => 'mock'],
        ]);

        Http::fake([
            'https://api.twitter.com/2/oauth2/token' => Http::response(['access_token' => 'app-token']),
            'https://api.twitter.com/2/trends/by/woeid/1' => Http::response([
                'data' => [
                    ['trend_name' => 'Budget cooking', 'tweet_count' => 1000],
                ],
            ]),
        ]);

        app(TrendEngineService::class)->fetchGlobalTrends(force: true);

        $this->assertDatabaseHas('trends', ['slug' => 'budget-cooking', 'name' => 'Budget cooking']);
        $this->assertDatabaseMissing('trends', ['slug' => 'transfer-deadline-drama']);
    }
}
