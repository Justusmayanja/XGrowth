<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\TikTok\TikTokDemandService;
use App\Support\TikTokCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokDemandServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_different_goals_rank_different_kinds_first(): void
    {
        $demand = app(TikTokDemandService::class);

        $forYou = $demand->ranked($this->user(['for_you']));
        $followers = $demand->ranked($this->user(['followers']));
        $traffic = $demand->ranked($this->user(['traffic']));

        $this->assertSame(TikTokCatalog::OPINION, $forYou[0]['kind']);
        $this->assertSame(TikTokCatalog::STORY, $followers[0]['kind']);
        $this->assertSame(TikTokCatalog::TIP, $traffic[0]['kind']);
    }

    public function test_learning_adjusts_a_kind_and_stays_inside_the_cap(): void
    {
        $demand = app(TikTokDemandService::class);
        $user = $this->user(['followers']);
        $before = $this->scoreFor($demand->ranked($user), TikTokCatalog::OPINION);

        TikTokClip::factory()->learned()->create([
            'user_id' => $user->id,
            'completion_percent' => 80,
            'package' => ['idea_kind' => TikTokCatalog::OPINION],
        ]);

        $this->assertSame($before + TikTokCatalog::LEARNING_STEP, $this->scoreFor($demand->ranked($user), TikTokCatalog::OPINION));

        TikTokClip::factory()->learned()->count(9)->create([
            'user_id' => $user->id,
            'completion_percent' => 90,
            'package' => ['idea_kind' => TikTokCatalog::OPINION],
        ]);

        $capped = $demand->ranked($user);
        $this->assertSame($before + TikTokCatalog::LEARNING_CAP, $this->scoreFor($capped, TikTokCatalog::OPINION));
        $this->assertSame(TikTokCatalog::STORY, $capped[0]['kind']);

        $penalized = $this->user(['followers']);
        $base = $this->scoreFor($demand->ranked($penalized), TikTokCatalog::OPINION);
        TikTokClip::factory()->learned()->count(10)->create([
            'user_id' => $penalized->id,
            'completion_percent' => 10,
            'package' => ['idea_kind' => TikTokCatalog::OPINION],
        ]);

        $after = $demand->ranked($penalized);
        $this->assertSame($base - TikTokCatalog::LEARNING_CAP, $this->scoreFor($after, TikTokCatalog::OPINION));
        $this->assertSame(TikTokCatalog::STORY, $after[0]['kind']);
    }

    public function test_recent_topics_are_not_ranked_first_and_discarded_topics_do_not_count(): void
    {
        $demand = app(TikTokDemandService::class);
        $user = $this->user(['for_you']);
        $first = $demand->ranked($user)[0];

        TikTokClip::factory()->discarded()->create([
            'user_id' => $user->id,
            'topic' => $first['topic'],
        ]);
        $this->assertSame($first['topic'], $demand->ranked($user)[0]['topic']);

        TikTokClip::factory()->waiting()->create([
            'user_id' => $user->id,
            'topic' => $first['topic'],
        ]);
        $this->assertNotSame($first['topic'], $demand->ranked($user)[0]['topic']);
    }

    public function test_ranking_is_deterministic_and_every_idea_is_scored_in_words(): void
    {
        $demand = app(TikTokDemandService::class);
        $user = $this->user(['for_you'], 'Underwater basket weaving');

        $first = $demand->ranked($user);
        $second = $demand->ranked($user);

        $this->assertSame($first, $second);
        $this->assertGreaterThanOrEqual(3, collect($first)->where('kind', TikTokCatalog::TIP)->count());

        foreach ($first as $idea) {
            $this->assertGreaterThanOrEqual(0, $idea['score']);
            $this->assertLessThanOrEqual(100, $idea['score']);
            $this->assertNotSame('', $idea['reason']);
            $this->assertStringContainsString('Underwater basket weaving', $idea['topic']);
        }
    }

    /**
     * @param  list<string>  $goals
     */
    private function user(array $goals, string $niche = 'Budget home cooking'): User
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'tiktok',
            'custom_niche' => $niche,
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => 'funny',
            'timezone' => 'Africa/Kampala',
            'onboarding_completed' => true,
            'discovery' => ['presence' => 'faceless'],
        ]);
        PlatformSetup::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'goals' => implode(',', $goals),
            'settings' => ['publish' => 'confirm'],
            'completed_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  list<array{kind: string, score: int}>  $ideas
     */
    private function scoreFor(array $ideas, string $kind): int
    {
        foreach ($ideas as $idea) {
            if ($idea['kind'] === $kind) {
                return $idea['score'];
            }
        }

        $this->fail('Missing kind '.$kind);
    }
}
