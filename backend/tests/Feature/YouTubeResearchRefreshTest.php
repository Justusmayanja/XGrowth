<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\YouTubeResearch;
use App\Models\YouTubeVideo;
use App\Support\YouTubeOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeResearchRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'openai.api_key' => '',
            'services.youtube.read_quota_daily' => 500,
            'services.youtube.research_cache_minutes' => 15,
        ]);
        Http::preventStrayRequests();
    }

    public function test_channel_and_niche_refreshes_are_cached_ranked_and_preserve_pasted_listings(): void
    {
        $user = $this->start();
        $this->link($user);
        $this->pasteListings($user);
        $pasted = YouTubeResearch::query()->where('user_id', $user->id)->value('listings');
        $this->fakeDataReads();

        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/youtube/research/channel-videos')->assertOk()
            ->assertJsonPath('research.used', 3)
            ->assertJsonPath('research.pulls.channel_videos.error', null);
        $firstCount = count(Http::recorded());
        $this->assertSame(3, $firstCount);

        $api->postJson('/api/youtube/research/channel-videos')->assertOk();
        $this->assertCount($firstCount, Http::recorded(), 'A fresh cache hit must make no HTTP calls.');

        $api->postJson('/api/youtube/research/niche-search', ['query' => 'budget cooking'])->assertOk()
            ->assertJsonPath('research.used', 104)
            ->assertJsonPath('research.pulls.niche_search.summary.query', 'budget cooking');

        $this->assertSame($pasted, YouTubeResearch::query()->where('user_id', $user->id)->value('listings'));
        $desk = $api->getJson('/api/dashboard/platform/youtube')->assertOk();
        $this->assertNotEmpty($desk->json('niche.working'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/youtube/v3/search'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'videos.insert'));
    }

    public function test_comments_views_and_retention_use_existing_learning_paths_and_keep_paste_fallbacks(): void
    {
        $user = $this->start();
        $this->link($user);
        $this->pasteListings($user);
        $video = YouTubeVideo::query()->where('user_id', $user->id)->firstOrFail();
        $this->fakeVideoReads();

        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/youtube/videos/'.$video->id.'/refresh-comments', [
            'youtube_video_id' => 'abc123XYZ_0',
        ])->assertOk()->assertJsonPath('pipeline.0.package.comments.counts.questions', 2);

        $api->postJson('/api/youtube/videos/'.$video->id.'/refresh-views', [
            'youtube_video_id' => 'abc123XYZ_0',
        ])->assertOk();
        $video->refresh();
        $this->assertSame(4321, $video->views);
        $this->assertSame(42.5, $video->average_view_percent);
        $this->assertSame('unshaped', $video->package['model_learning']['case']);

        $api->postJson('/api/youtube/videos/'.$video->id.'/refresh-retention', [
            'youtube_video_id' => 'abc123XYZ_0',
        ])->assertOk();
        $video->refresh();
        $this->assertSame('hook_leak', $video->package['model_learning']['case']);
        $this->assertTrue($video->package['model_learning']['change_opening']);
        $this->assertSame('abc123XYZ_0', $video->package['research_source']['youtube_video_id']);

        $api->postJson('/api/youtube/videos/'.$video->id.'/comments', [
            'comments' => "How do budget meals work?\nHow do budget meals actually work?",
        ])->assertOk();
        $api->postJson('/api/youtube/videos/'.$video->id.'/retention', [
            'csv' => "0,100\n5,95\n10,90\n15,85\n20,80\n25,75\n30,70\n40,69",
        ])->assertOk();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'videos.insert'));
    }

    public function test_quota_failure_keeps_cached_research_and_surfaces_the_reason(): void
    {
        config(['services.youtube.read_quota_daily' => 3]);
        $user = $this->start();
        $this->link($user);
        $this->fakeDataReads();
        $api = $this->actingAs($user, 'sanctum');

        $api->postJson('/api/youtube/research/channel-videos')->assertOk();
        $calls = count(Http::recorded());
        $response = $api->postJson('/api/youtube/research/niche-search', ['query' => 'budget cooking'])
            ->assertOk()
            ->assertJsonPath('research.used', 3);

        $this->assertStringContainsString('spent for today', (string) $response->json('research.pulls.niche_search.error'));
        $this->assertNotNull($response->json('research.pulls.channel_videos.refreshed_at'));
        $this->assertCount($calls, Http::recorded());
    }

    public function test_youtube_run_makes_zero_youtube_http_calls(): void
    {
        $this->start();
        Http::fake();

        $this->artisan('youtube:run')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function start(): User
    {
        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/account/setup/platforms', ['platforms' => ['youtube']])->assertOk();
        $api->postJson('/api/account/setup/about', [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'I cook cheap weeknight meals and share practical shortcuts.',
            'audience' => 'Students cooking for one',
            'tone' => ['funny'],
        ])->assertOk();
        $api->postJson('/api/account/setup/platforms/youtube', [
            'goals' => ['search'],
            'publish' => 'auto',
            'video_mode' => 'short',
            'format' => 'text_on_screen',
        ])->assertOk();

        return $user;
    }

    private function link(User $user): void
    {
        ConnectedAccount::query()->create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'platform_account_id' => 'UC-RESEARCH',
            'username' => 'Research Kitchen',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => YouTubeOAuth::READONLY_SCOPE.' '.YouTubeOAuth::ANALYTICS_READONLY_SCOPE,
            'is_active' => true,
        ]);
    }

    private function pasteListings(User $user): void
    {
        $videos = [];
        foreach ([100, 120, 90, 1000] as $index => $views) {
            $videos[] = ['channel' => 'Pasted Kitchen', 'title' => 'Budget cooking idea '.$index, 'views' => $views];
        }
        $this->actingAs($user, 'sanctum')->postJson('/api/youtube/listings', ['videos' => $videos])->assertOk();
    }

    private function fakeDataReads(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/channels')) {
                return Http::response(['items' => [[
                    'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-RESEARCH']],
                ]]]);
            }
            if (str_contains($request->url(), '/playlistItems')) {
                return Http::response(['items' => collect(range(1, 4))->map(fn ($id) => [
                    'contentDetails' => ['videoId' => 'channel-'.$id],
                ])->all()]);
            }
            if (str_contains($request->url(), '/search')) {
                return Http::response(['items' => collect(range(1, 4))->map(fn ($id) => [
                    'id' => ['videoId' => 'search-'.$id],
                ])->all()]);
            }
            if (str_contains($request->url(), '/videos')) {
                $ids = explode(',', (string) $request['id']);

                return Http::response(['items' => collect($ids)->values()->map(fn ($id, $index) => [
                    'id' => $id,
                    'snippet' => ['channelTitle' => 'API Kitchen', 'title' => 'Budget cooking API '.$index],
                    'statistics' => ['viewCount' => (string) ([100, 120, 90, 2000][$index] ?? 100)],
                    'contentDetails' => ['duration' => 'PT4M'],
                ])->all()]);
            }

            return Http::response(['error' => ['message' => 'unexpected']], 500);
        });
    }

    private function fakeVideoReads(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/commentThreads')) {
                return Http::response(['items' => [
                    ['snippet' => ['topLevelComment' => ['snippet' => ['textDisplay' => 'How do budget meals work?']]]],
                    ['snippet' => ['topLevelComment' => ['snippet' => ['textDisplay' => 'How do budget meals actually work?']]]],
                    ['snippet' => ['topLevelComment' => ['snippet' => ['textDisplay' => 'This was helpful']]]],
                ]]);
            }
            if (str_contains((string) $request['metrics'], 'averageViewPercentage')) {
                return Http::response(['rows' => [[4321, 42.5]]]);
            }
            if ((string) $request['metrics'] === 'audienceWatchRatio') {
                return Http::response(['rows' => [
                    [0, 1], [.05, .9], [.1, .74], [.15, .72], [.2, .7],
                    [.25, .68], [.3, .65], [.5, .64], [.75, .63], [1, .62],
                ]]);
            }

            return Http::response(['error' => ['message' => 'unexpected']], 500);
        });
    }
}
