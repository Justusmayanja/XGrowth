<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\Trend;
use App\Models\User;
use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
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
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response(['data' => []]),
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
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response(['data' => []]),
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

    public function test_the_topics_page_drafts_a_few_current_topics(): void
    {
        config(['openai.api_key' => 'test-key']);
        $user = User::factory()->create();
        $user->accountProfile()->create([
            'custom_niche' => 'Manchester United',
            'description' => 'A supporter who answers the league from this club.',
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
            'https://api.twitter.com/2/users/personalized_trends*' => Http::response([
                'data' => [
                    ['trend_name' => 'Arsenal and City in form', 'tweet_count' => 18420],
                    ['trend_name' => 'Nicolas Jackson', 'tweet_count' => 900],
                    ['trend_name' => '日本シリーズ'],
                    ['trend_name' => '#集えNIKKE4周年'],
                ],
            ]),
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response([
                'data' => [
                    [
                        'id' => '10',
                        'text' => 'Manchester United lost and the next manager is the argument',
                        'author_id' => '8',
                        'lang' => 'en',
                        'created_at' => now()->subMinutes(6)->toIso8601String(),
                        'public_metrics' => ['like_count' => 20, 'reply_count' => 3],
                    ],
                    [
                        'id' => '11',
                        'text' => 'Trump and Iran are at war tonight',
                        'author_id' => '7',
                        'lang' => 'en',
                        'created_at' => now()->subMinutes(4)->toIso8601String(),
                        'public_metrics' => ['like_count' => 4000, 'reply_count' => 80],
                    ],
                ],
                'includes' => [
                    'users' => [
                        ['id' => '8', 'username' => 'utdnews', 'public_metrics' => ['followers_count' => 120000]],
                        ['id' => '7', 'username' => 'worldnews', 'public_metrics' => ['followers_count' => 5000000]],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/trends')
            ->assertOk()
            ->assertJsonPath('source', 'home')
            ->assertJsonCount(1, 'trends')
            ->assertJsonPath('trends.0.name', 'Manchester United lost and the next manager is the argument')
            ->assertJsonPath('trends.0.draft', '')
            ->assertJsonMissing(['name' => 'Arsenal and City in form'])
            ->assertJsonMissing(['name' => 'Trump and Iran are at war tonight']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/trends')
            ->assertJsonPath('trends.0.name', 'Manchester United lost and the next manager is the argument');

        Http::assertSentCount(1);
    }

    public function test_topics_skip_other_languages_and_hashtags(): void
    {
        config(['openai.api_key' => '']);
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
                    ['trend_name' => 'たかほー'],
                    ['trend_name' => '#LulaEmCopacabana'],
                    ['trend_name' => '#sbhawks'],
                    ['trend_name' => '日本シリーズ'],
                    ['trend_name' => '#YouManiacSeriesEP9'],
                ],
            ]),
            'https://api.twitter.com/2/trends/by/woeid/23424977*' => Http::response([
                'data' => [
                    ['trend_name' => 'Rent prices this week', 'tweet_count' => 12000],
                    ['trend_name' => 'Arsenal and City in form', 'tweet_count' => 8400],
                    ['trend_name' => '#sbhawks', 'tweet_count' => 900],
                ],
            ]),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/trends')
            ->assertOk()
            ->assertJsonPath('trends.0.name', 'Rent prices this week')
            ->assertJsonPath('trends.1.name', 'Arsenal and City in form')
            ->assertJsonCount(2, 'trends');

        $body = $response->getContent();
        $this->assertStringNotContainsString('たかほー', $body);
        $this->assertStringNotContainsString('日本シリーズ', $body);
        $this->assertStringNotContainsString('#sbhawks', $body);
        $this->assertStringNotContainsString('#YouManiacSeriesEP9', $body);
    }

    public function test_niche_search_keeps_english_posts_about_the_niche(): void
    {
        $user = User::factory()->create();
        $user->accountProfile()->create([
            'custom_niche' => 'budget cooking',
            'description' => 'Cheap weeknight meals for one.',
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
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response([
                'data' => [
                    ['id' => '1', 'text' => 'たかほー #sbhawks', 'public_metrics' => ['like_count' => 4]],
                    [
                        'id' => '2',
                        'text' => 'Budget cooking rice prices jumped again this week',
                        'public_metrics' => ['like_count' => 9, 'impression_count' => 100],
                    ],
                ],
            ]),
        ]);

        $ranked = app(TrendEngineService::class)->rankedInNiche($user);

        $this->assertNotNull($ranked);
        $this->assertCount(1, $ranked);
        $this->assertSame('Budget cooking rice prices jumped again this week', $ranked[0]['trend']->name);
    }

    public function test_a_worldwide_trend_is_not_added_to_a_niche_account(): void
    {
        $user = User::factory()->create();
        $user->accountProfile()->create([
            'custom_niche' => 'Man United',
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
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response([
                'data' => [
                    [
                        'id' => '9',
                        'text' => 'Trump and Iran are at war tonight',
                        'author_id' => '7',
                        'created_at' => now()->subMinutes(8)->toIso8601String(),
                        'public_metrics' => ['like_count' => 4000, 'reply_count' => 800],
                    ],
                    [
                        'id' => '10',
                        'text' => 'Man United have scored and the ground has gone',
                        'author_id' => '8',
                        'created_at' => now()->subMinutes(6)->toIso8601String(),
                        'public_metrics' => ['like_count' => 20, 'reply_count' => 3],
                    ],
                ],
                'includes' => [
                    'users' => [
                        ['id' => '7', 'username' => 'worldnews', 'public_metrics' => ['followers_count' => 5000000]],
                        ['id' => '8', 'username' => 'utdnews', 'public_metrics' => ['followers_count' => 120000]],
                    ],
                ],
            ]),
            'https://api.twitter.com/2/trends/by/woeid/1*' => Http::response([
                'data' => [
                    ['trend_name' => 'Trump and Iran', 'tweet_count' => 90000],
                ],
            ]),
        ]);

        $ranked = app(TrendEngineService::class)->rankedInNiche($user);

        $this->assertCount(1, $ranked);
        $this->assertSame('Man United have scored and the ground has gone', $ranked[0]['trend']->name);
        $this->assertStringNotContainsString('Trump', json_encode($ranked));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/users/42/timelines/reverse_chronological');
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/tweets/search/recent'));
    }

    public function test_topics_fall_back_to_the_accounts_own_posts(): void
    {
        config(['openai.api_key' => '']);
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
            'https://api.twitter.com/2/trends/by/woeid/23424977*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/23424975*' => Http::response(['data' => []], 404),
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
            ->assertJsonPath('trends.0.name', 'Rice again')
            ->assertJsonPath('trends.0.draft', '');

        $this->assertStringNotContainsString('X did not send a count', $this->actingAs($user, 'sanctum')->getJson('/api/trends')->getContent());
    }

    public function test_replies_are_capped_at_five_and_the_loudest_come_first(): void
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

        $tweets = [];
        for ($i = 1; $i <= 7; $i++) {
            $tweets[] = [
                'id' => (string) $i,
                'text' => 'Mention '.$i,
                'author_id' => '7',
                'created_at' => now()->subMinutes($i)->toIso8601String(),
                'public_metrics' => [
                    'like_count' => $i === 6 ? 40 : $i,
                    'retweet_count' => 0,
                    'reply_count' => 0,
                    'impression_count' => 10,
                ],
            ];
        }

        Http::fake([
            'https://api.twitter.com/2/users/42/mentions*' => Http::response([
                'data' => $tweets,
                'includes' => [
                    'users' => [[
                        'id' => '7',
                        'username' => 'cook',
                        'public_metrics' => ['followers_count' => 120],
                    ]],
                ],
            ]),
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response(['data' => []]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/engagement/feed')
            ->assertOk()
            ->assertJsonCount(5, 'posts')
            ->assertJsonPath('posts.0.id', '6')
            ->assertJsonPath('stats.conversations', 5);
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
            'https://api.twitter.com/2/trends/by/woeid/23424977*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/23424975*' => Http::response(['data' => []], 404),
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
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response([
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
            ->assertJsonPath('metrics.1.sub', 'Recent posts and comments')
            ->assertJsonPath('metrics.2.value', 2.5);

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
            'https://api.twitter.com/2/trends/by/woeid/23424977*' => Http::response(['data' => []], 404),
            'https://api.twitter.com/2/trends/by/woeid/23424975*' => Http::response(['data' => []], 404),
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
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response(['data' => []]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('metrics.1.value', 200)
            ->assertJsonPath('metrics.1.sub', 'Recent posts and comments')
            ->assertJsonPath('metrics.2.value', 3);
    }

    public function test_view_counts_come_from_organic_metrics_when_public_metrics_omit_them(): void
    {
        [$user] = $this->connectedCook();

        Http::fake([
            'https://api.twitter.com/2/users/me*' => Http::response([
                'data' => [
                    'id' => '42',
                    'public_metrics' => ['followers_count' => 18],
                ],
            ]),
            'https://api.twitter.com/2/users/42/tweets*' => Http::response([
                'data' => [
                    $this->ownTweet('2', 'a cheap dinner for budget cooking', 0, likes: 4, organicImpressions: 900, profileClicks: 7),
                ],
            ]),
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response([
                'data' => [
                    $this->ownTweet('2', 'a cheap dinner for budget cooking', 0, likes: 4),
                ],
            ]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('metrics.0.value', 18)
            ->assertJsonPath('metrics.1.value', 900);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/analytics/growth')
            ->assertOk()
            ->assertJsonPath('summary.followers', 18)
            ->assertJsonPath('summary.impressions_7d', 900)
            ->assertJsonPath('summary.profile_visits_7d', 7);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/users/42/tweets')) {
                return false;
            }

            $fields = (string) ($request->data()['tweet.fields'] ?? '');

            return str_contains($fields, 'organic_metrics')
                && str_contains($fields, 'non_public_metrics');
        });
    }

    public function test_missing_view_counts_stay_blank_instead_of_zero(): void
    {
        [$user] = $this->connectedCook();
        $tweet = [
            'id' => '2',
            'text' => 'a cheap dinner for budget cooking',
            'author_id' => '42',
            'created_at' => now()->subHours(2)->toIso8601String(),
            'public_metrics' => [
                'like_count' => 4,
                'retweet_count' => 1,
                'reply_count' => 0,
            ],
        ];

        Http::fake([
            'https://api.twitter.com/2/users/me*' => Http::response([
                'data' => [
                    'id' => '42',
                    'public_metrics' => ['followers_count' => 18],
                ],
            ]),
            'https://api.twitter.com/2/users/42/tweets*' => Http::response(['data' => [$tweet]]),
            'https://api.twitter.com/2/users/42/timelines/reverse_chronological*' => Http::response(['data' => [$tweet]]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/x')
            ->assertOk()
            ->assertJsonPath('metrics.0.value', 18)
            ->assertJsonPath('metrics.1.label', 'Reactions')
            ->assertJsonPath('metrics.1.value', 5)
            ->assertJsonPath('metrics.1.sub', 'Likes, replies, and reposts. X did not send view counts.')
            ->assertJsonPath('metrics.2.label', 'Replies')
            ->assertJsonPath('metrics.2.value', 0);
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
    private function ownTweet(string $id, string $text, int $impressions, bool $comment = false, int $likes = 2, int $organicImpressions = 0, int $profileClicks = 0): array
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

        if ($organicImpressions > 0 || $profileClicks > 0) {
            $row['organic_metrics'] = [
                'impression_count' => $organicImpressions,
                'user_profile_clicks' => $profileClicks,
            ];
            unset($row['public_metrics']['impression_count']);
        }

        if ($comment) {
            $row['referenced_tweets'] = [['type' => 'replied_to', 'id' => '900']];
        }

        return $row;
    }
}
