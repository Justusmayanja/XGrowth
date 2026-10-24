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

    public function test_the_reply_feed_uses_mentions_on_the_account(): void
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
                    'id' => '55',
                    'text' => 'A mention on the account',
                    'author_id' => '7',
                    'created_at' => now()->subMinutes(5)->toIso8601String(),
                    'public_metrics' => [
                        'like_count' => 3,
                        'retweet_count' => 1,
                        'reply_count' => 2,
                        'impression_count' => 40,
                    ],
                ]],
                'includes' => [
                    'users' => [[
                        'id' => '7',
                        'username' => 'cook',
                        'public_metrics' => ['followers_count' => 120],
                    ]],
                ],
            ]),
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/engagement/feed')
            ->assertOk()
            ->assertJsonPath('posts.0.id', '55')
            ->assertJsonPath('posts.0.author', '@cook')
            ->assertJsonPath('posts.0.likes', 3)
            ->assertJsonPath('posts.0.impressions', 40)
            ->assertJsonPath('stats.likes', 3)
            ->assertJsonPath('stats.impressions', 40)
            ->assertJsonMissing(['author' => '@niche_insider']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/users/42/mentions'));
        Http::assertNotSent(function ($request) {
            if (! str_contains($request->url(), '/tweets/search/recent')) {
                return false;
            }

            return str_contains((string) ($request->data()['query'] ?? ''), 'cooking');
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
            ->assertJsonPath('posts.0.likes', 1)
            ->assertJsonPath('stats.conversations', 1);
    }

    public function test_the_topics_page_uses_counts_from_the_connected_account(): void
    {
        $user = User::factory()->create();
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
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response([
                'data' => [
                    ['trend_name' => 'Arsenal and City in form', 'tweet_count' => 18420],
                ],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/trends')
            ->assertOk()
            ->assertJsonPath('source', 'trends')
            ->assertJsonPath('trends.0.name', 'Arsenal and City in form')
            ->assertJsonPath('trends.0.posts', 18420)
            ->assertJsonPath('trends.0.figures', '18,420 posts');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/trends')
            ->assertJsonPath('trends.0.posts', 18420);

        Http::assertSentCount(1);
    }

    public function test_topics_fall_back_to_the_accounts_own_posts(): void
    {
        $user = User::factory()->create();
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
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/1*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/users/42/tweets*' => Http::response([
                'data' => [[
                    'id' => '9',
                    'text' => 'Rice again #weeknights',
                    'created_at' => now()->subHour()->toIso8601String(),
                    'public_metrics' => [
                        'impression_count' => 320,
                        'like_count' => 14,
                        'reply_count' => 3,
                        'retweet_count' => 1,
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/trends')
            ->assertOk()
            ->assertJsonPath('source', 'posts')
            ->assertJsonPath('trends.0.name', '#weeknights')
            ->assertJsonPath('trends.0.impressions', 320)
            ->assertJsonPath('trends.0.likes', 14)
            ->assertJsonPath('trends.0.replies', 3);
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

    public function test_impressions_use_posts_and_comments_x_ranks_for_the_niche(): void
    {
        [$user] = $this->connectedCook();

        Http::fake([
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/1*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/users/me*' => Http::response([
                'data' => [
                    'id' => '42',
                    'public_metrics' => ['followers_count' => 18],
                ],
            ]),
            'https://api.twitter.com/2/users/42/tweets*' => Http::response([
                'data' => [
                    $this->ownTweet('1', 'hello world', 5000),
                    $this->ownTweet('2', 'a cheap dinner for budget cooking', 120),
                    $this->ownTweet('3', 'same, budget cooking is how I do rice', 80, comment: true),
                ],
            ]),
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response([
                'data' => [
                    $this->ownTweet('3', 'same, budget cooking is how I do rice', 80, comment: true),
                    $this->ownTweet('2', 'a cheap dinner for budget cooking', 120),
                ],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('metrics.1.value', 200)
            ->assertJsonPath('metrics.1.sub', 'Posts and comments, ranked for your niche')
            ->assertJsonPath('metrics.2.value', 2.5);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tweets/search/recent')) {
                return false;
            }

            $query = (string) ($request->data()['query'] ?? '');

            return ($request->data()['sort_order'] ?? '') === 'relevancy'
                && str_contains($query, 'from:mackie')
                && str_contains($query, 'cooking')
                && ! str_contains($query, '-is:reply');
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/users/42/tweets')) {
                return false;
            }

            return ($request->data()['exclude'] ?? '') === 'retweets';
        });
    }

    public function test_impressions_fall_back_to_niche_posts_and_comments_when_search_is_empty(): void
    {
        [$user] = $this->connectedCook();

        Http::fake([
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/1*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/users/me*' => Http::response([
                'data' => [
                    'id' => '42',
                    'public_metrics' => ['followers_count' => 18],
                ],
            ]),
            'https://api.twitter.com/2/users/42/tweets*' => Http::response([
                'data' => [
                    $this->ownTweet('1', 'hello world', 5000),
                    $this->ownTweet('2', 'a cheap dinner for budget cooking', 120, likes: 4),
                    $this->ownTweet('3', 'same, budget cooking is how I do rice', 80, comment: true, likes: 1),
                ],
            ]),
            'https://api.twitter.com/2/tweets/search/recent*' => Http::response(['data' => []]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('metrics.1.value', 200)
            ->assertJsonPath('metrics.1.sub', 'Posts and comments, ranked for your niche')
            ->assertJsonPath('metrics.2.value', 3);
    }

    /**
     * @return array{0: User}
     */
    private function connectedCook(): array
    {
        $user = User::factory()->create();
        $user->accountProfile()->create([
            'custom_niche' => 'budget cooking',
            'onboarding_completed' => false,
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

        return [$user];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownTweet(string $id, string $text, int $impressions, bool $comment = false, int $likes = 2): array
    {
        $row = [
            'id' => $id,
            'text' => $text,
            'author_id' => '42',
            'created_at' => now()->subHours(2)->toIso8601String(),
            'public_metrics' => [
                'impression_count' => $impressions,
                'like_count' => $likes,
                'retweet_count' => $comment ? 0 : 1,
                'reply_count' => 0,
            ],
        ];

        if ($comment) {
            $row['referenced_tweets'] = [['type' => 'replied_to', 'id' => '900']];
        }

        return $row;
    }
}
