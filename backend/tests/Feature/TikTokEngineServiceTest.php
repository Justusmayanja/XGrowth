<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\PlatformSetup;
use App\Models\Post;
use App\Models\RateLimitBudget;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\TikTok\TikTokEngineService;
use App\Services\TikTok\TikTokScriptWriter;
use App\Support\TikTokCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class TikTokEngineServiceTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openai.api_key' => '']);
        Http::preventStrayRequests();
    }

    public function test_one_run_creates_a_waiting_clip_and_the_second_does_not(): void
    {
        $user = $this->readyUser();

        $first = app(TikTokEngineService::class)->run($user);

        $this->assertSame(TikTokCatalog::CREATED, $first['outcome']);
        $clip = $first['clip']->fresh();
        $this->assertSame(TikTokCatalog::WAITING, $clip->status);
        $this->assertTrue($clip->suggested_for->greaterThan(now()->subMinute()));
        $this->assertFalse($clip->package['render']['ready']);
        $this->assertFalse($clip->package['publish']['uploaded']);
        $this->assertSame('pending', $clip->package['render']['provider']);
        $this->assertSame('confirm', $clip->package['publish']['mode']);
        $this->assertSame('Africa/Kampala', $clip->package['timezone']);
        $this->assertFalse($clip->package['timezone_assumed']);
        $this->assertNotSame('', $clip->hook);
        $this->assertLessThanOrEqual(TikTokCatalog::CAPTION_MAX_CHARS, mb_strlen((string) $clip->caption));

        $second = app(TikTokEngineService::class)->run($user);

        $this->assertSame(TikTokCatalog::SKIPPED, $second['outcome']);
        $this->assertSame(TikTokCatalog::OPEN_CLIP, $second['reason']);
        $this->assertSame(1, TikTokClip::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, TikTokClip::query()->where('status', TikTokCatalog::DRAFTED)->count());
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, RateLimitBudget::query()->count());
    }

    public function test_learned_and_discarded_clips_do_not_block_a_new_one(): void
    {
        $learned = $this->readyUser();
        TikTokClip::factory()->learned()->create(['user_id' => $learned->id]);
        TikTokClip::factory()->discarded()->create(['user_id' => $learned->id]);

        $result = app(TikTokEngineService::class)->run($learned);

        $this->assertSame(TikTokCatalog::CREATED, $result['outcome']);
        $this->assertSame(TikTokCatalog::WAITING, $result['clip']->status);
    }

    public function test_drafted_waiting_and_queued_clips_block(): void
    {
        foreach (['drafted', 'waiting', 'queued'] as $state) {
            $user = $this->readyUser();
            TikTokClip::factory()->{$state}()->create(['user_id' => $user->id]);

            $result = app(TikTokEngineService::class)->run($user);

            $this->assertSame(TikTokCatalog::OPEN_CLIP, $result['reason']);
            $this->assertSame(1, TikTokClip::query()->where('user_id', $user->id)->count());
        }
    }

    public function test_blocked_incomplete_and_unfinished_setup_skip(): void
    {
        $blocked = $this->readyUser();
        $blocked->update(['is_blocked' => true]);
        $this->assertSame(TikTokCatalog::BLOCKED, app(TikTokEngineService::class)->run($blocked->fresh())['reason']);

        $new = User::factory()->create();
        AccountProfile::create([
            'user_id' => $new->id,
            'purpose' => 'creator',
            'platforms' => 'tiktok',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals.',
            'audience' => 'Students',
            'tone' => 'funny',
            'onboarding_completed' => false,
        ]);
        $this->assertSame(TikTokCatalog::NOT_ONBOARDED, app(TikTokEngineService::class)->run($new)['reason']);

        $onboarded = $this->readyUser();
        PlatformSetup::query()->where('user_id', $onboarded->id)->delete();
        $this->assertSame(TikTokCatalog::SETUP_INCOMPLETE, app(TikTokEngineService::class)->run($onboarded)['reason']);

        $unfinished = $this->readyUser();
        PlatformSetup::query()->where('user_id', $unfinished->id)->update(['completed_at' => null]);
        $this->assertSame(TikTokCatalog::SETUP_INCOMPLETE, app(TikTokEngineService::class)->run($unfinished)['reason']);

        $this->assertSame(0, TikTokClip::query()->count());
    }

    public function test_a_held_lock_returns_busy_and_creates_nothing(): void
    {
        $user = $this->readyUser();
        $lock = Cache::lock('tiktok-run-'.$user->id, 30);
        $this->assertTrue($lock->get());

        try {
            $result = app(TikTokEngineService::class)->run($user);
            $this->assertSame(TikTokCatalog::BUSY, $result['reason']);
            $this->assertSame(0, TikTokClip::query()->count());
        } finally {
            $lock->release();
        }
    }

    public function test_overlapping_runs_create_at_most_one_clip(): void
    {
        $user = $this->readyUser();
        $second = null;

        $this->mock(TikTokScriptWriter::class, function ($mock) use (&$second, $user) {
            $mock->shouldReceive('clip')->once()->andReturnUsing(function () use (&$second, $user) {
                $second = app(TikTokEngineService::class)->run($user);

                return $this->script();
            });
        });

        $first = app(TikTokEngineService::class)->run($user);

        $this->assertSame(TikTokCatalog::CREATED, $first['outcome']);
        $this->assertSame(TikTokCatalog::BUSY, $second['reason']);
        $this->assertSame(1, TikTokClip::query()->where('user_id', $user->id)->count());
    }

    public function test_a_throwing_writer_still_stores_a_fallback_clip(): void
    {
        $warnings = [];
        Log::listen(function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });
        $user = $this->readyUser();

        $this->mock(TikTokScriptWriter::class, function ($mock) {
            $mock->shouldReceive('clip')->once()->andThrow(new \RuntimeException('model down'));
            $mock->shouldReceive('fallback')->once()->andReturn($this->script(TikTokCatalog::SOURCE_FALLBACK));
        });

        $result = app(TikTokEngineService::class)->run($user);

        $this->assertSame(TikTokCatalog::CREATED, $result['outcome']);
        $this->assertSame(TikTokCatalog::WAITING, $result['clip']->status);
        $this->assertSame(TikTokCatalog::SOURCE_FALLBACK, $result['clip']->package['source']);
        $this->assertFalse($result['clip']->package['render']['ready']);
        $this->assertFalse($result['clip']->package['publish']['uploaded']);
        $this->assertTrue(collect($warnings)->contains(fn (string $message) => str_contains($message, 'local writer')));
    }

    public function test_the_latest_learning_note_is_passed_to_the_writer(): void
    {
        $user = $this->readyUser();
        TikTokClip::factory()->learned()->create([
            'user_id' => $user->id,
            'learning_note' => 'Older note',
            'learned_at' => now()->subDay(),
        ]);
        TikTokClip::factory()->learned()->create([
            'user_id' => $user->id,
            'learning_note' => 'People stayed with the question',
        ]);
        $seen = null;

        $this->mock(TikTokScriptWriter::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('clip')->once()->andReturnUsing(function ($user, $profile, $idea, $note) use (&$seen) {
                $seen = $note;

                return $this->script();
            });
        });

        app(TikTokEngineService::class)->run($user);

        $this->assertSame('People stayed with the question', $seen);
    }

    private function readyUser(): User
    {
        $user = User::factory()->create();
        AccountProfile::create([
            'user_id' => $user->id,
            'purpose' => 'creator',
            'platforms' => 'tiktok',
            'custom_niche' => 'Budget home cooking',
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
            'goals' => 'for_you',
            'settings' => ['publish' => 'confirm'],
            'completed_at' => now(),
        ]);
        $this->giveTikTokAllowance($user);

        return $user->fresh('accountProfile');
    }

    /**
     * @return array{topic: string, idea_kind: string, hook: string, spoken_lines: list<string>, caption: string, on_screen_text: list<string>, shots: list<string>, sound_note: string, source: string}
     */
    private function script(string $source = TikTokCatalog::SOURCE_MODEL): array
    {
        return [
            'topic' => 'Salt the pasta water',
            'idea_kind' => TikTokCatalog::OPINION,
            'hook' => 'You are salting the water too late.',
            'spoken_lines' => [
                'You are salting the water too late.',
                'The water should taste like the sea first.',
                'Then the pasta goes in.',
                'That is the whole clip.',
            ],
            'caption' => "You are salting the water too late.\nTaste it before the pasta goes in.\n#budgetcooking #weeknights #pasta",
            'on_screen_text' => ['Salt it first', 'Taste the water'],
            'shots' => ['Pot on the stove', 'Salt in the hand', 'Pasta going in'],
            'sound_note' => 'Quiet kitchen',
            'source' => $source,
        ];
    }
}
