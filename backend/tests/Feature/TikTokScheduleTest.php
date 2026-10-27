<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\TikTok\TikTokDemandService;
use App\Services\TikTok\TikTokEngineService;
use App\Services\TikTok\TikTokScriptWriter;
use App\Support\TikTokCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class TikTokScheduleTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openai.api_key' => '']);
        Http::preventStrayRequests();
    }

    public function test_run_all_counts_outcomes_and_skips_the_model_when_a_guard_fails(): void
    {
        $open = $this->readyUser();
        TikTokClip::factory()->waiting()->create(['user_id' => $open->id]);
        $unpaid = $this->readyUser(allowance: false);
        $blocked = $this->readyUser();
        $blocked->update(['is_blocked' => true]);
        $plain = User::factory()->create();

        $this->mock(TikTokScriptWriter::class, function ($mock) {
            $mock->shouldNotReceive('clip');
            $mock->shouldNotReceive('fallback');
        });

        $summary = app(TikTokEngineService::class)->runAll();

        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(1, $summary['skipped'][TikTokCatalog::OPEN_CLIP]);
        $this->assertSame(1, $summary['skipped'][TikTokCatalog::NO_PLAN]);
        $this->assertSame(1, TikTokClip::query()->where('user_id', $open->id)->count());
        $this->assertSame(0, TikTokClip::query()->where('user_id', $unpaid->id)->count());
        $this->assertSame(0, TikTokClip::query()->where('user_id', $blocked->id)->count());
        $this->assertSame(0, TikTokClip::query()->where('user_id', $plain->id)->count());
    }

    public function test_one_account_failing_does_not_stop_the_others(): void
    {
        $bad = $this->readyUser();
        $good = $this->readyUser();
        $idea = [
            'kind' => TikTokCatalog::TIP,
            'topic' => 'Salt the pasta water',
            'angle' => 'One step, then stop.',
            'score' => 40,
            'reason' => 'It fits the account.',
        ];

        $this->mock(TikTokDemandService::class, function ($mock) use ($bad, $idea) {
            $mock->shouldReceive('ranked')->andReturnUsing(function (User $user) use ($bad, $idea) {
                if ($user->id === $bad->id) {
                    throw new \RuntimeException('down');
                }

                return [$idea];
            });
        });

        $summary = app(TikTokEngineService::class)->runAll();

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, TikTokClip::query()->where('user_id', $bad->id)->count());
        $this->assertSame(1, TikTokClip::query()->where('user_id', $good->id)->where('status', TikTokCatalog::WAITING)->count());
    }

    public function test_a_held_lock_makes_run_all_skip_that_account(): void
    {
        $user = $this->readyUser();
        $lock = Cache::lock('tiktok-run-'.$user->id, 30);
        $this->assertTrue($lock->get());

        try {
            $summary = app(TikTokEngineService::class)->runAll();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $summary['created']);
        $this->assertSame(1, $summary['skipped'][TikTokCatalog::BUSY]);
        $this->assertSame(0, TikTokClip::query()->where('user_id', $user->id)->count());
    }

    public function test_the_command_and_the_hourly_schedule_exist(): void
    {
        $user = $this->readyUser();

        $this->artisan('tiktok:run', ['--user' => $user->id])->assertSuccessful();
        $this->assertSame(1, TikTokClip::query()->where('user_id', $user->id)->count());

        $this->artisan('tiktok:run', ['--user' => 999999])->assertFailed();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'tiktok-engine');

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(15, $event->expiresAt);
    }

    public function test_diagnose_is_read_only_and_names_the_guard(): void
    {
        $user = $this->readyUser();
        $clip = TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        $updated = $clip->fresh()->updated_at?->toIso8601String();
        $before = TikTokClip::query()->count();

        $diagnosis = app(TikTokEngineService::class)->diagnose($user->fresh());

        $this->assertFalse($diagnosis['can_run']);
        $this->assertSame(TikTokCatalog::OPEN_CLIP, $diagnosis['reason']);
        $this->assertSame(TikTokCatalog::skipMessage(TikTokCatalog::OPEN_CLIP), $diagnosis['message']);
        $this->assertSame($before, TikTokClip::query()->count());
        $this->assertSame($updated, $clip->fresh()->updated_at?->toIso8601String());

        $clip->delete();
        $clear = app(TikTokEngineService::class)->diagnose($user->fresh());
        $this->assertTrue($clear['can_run']);
        $this->assertNull($clear['reason']);
        $this->assertSame($before - 1, TikTokClip::query()->count());
    }

    public function test_admin_tiktok_operations_require_an_admin(): void
    {
        $customer = $this->readyUser();
        TikTokClip::factory()->waiting()->create([
            'user_id' => $customer->id,
            'created_at' => now()->subDays(8),
        ]);
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/admin/tiktok')
            ->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/tiktok')
            ->assertOk()
            ->assertJsonPath('counts.waiting', 1)
            ->assertJsonPath('attention.0.email', $customer->email)
            ->assertJsonPath('attention.0.status', TikTokCatalog::WAITING);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/tiktok/diagnose?user='.$customer->id)
            ->assertOk()
            ->assertJsonPath('diagnose.reason', TikTokCatalog::OPEN_CLIP);

        $this->mock(TikTokEngineService::class, function ($mock) {
            $mock->shouldReceive('runAll')->once()->andReturn([
                'created' => 0,
                'skipped' => [TikTokCatalog::OPEN_CLIP => 1],
                'failed' => 0,
            ]);
        });

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/tiktok/run')
            ->assertOk()
            ->assertJsonPath('summary.skipped.open_clip', 1);
    }

    public function test_ideas_and_clip_history_are_scoped_and_have_no_side_effects(): void
    {
        $user = $this->readyUser();
        $other = $this->readyUser();
        TikTokClip::factory()->learned()->create(['user_id' => $user->id, 'views' => 10, 'follows' => 2, 'completion_percent' => 40]);
        TikTokClip::factory()->learned()->create(['user_id' => $other->id, 'views' => 99]);
        TikTokClip::factory()->discarded()->create(['user_id' => $user->id]);
        $before = TikTokClip::query()->count();

        $ideas = $this->actingAs($user, 'sanctum')->getJson('/api/tiktok/ideas')->assertOk();
        $first = $ideas->json('data.0');
        $this->assertIsArray($first);
        $this->assertArrayNotHasKey('score', $first);
        $this->assertArrayHasKey('kind_label', $first);
        $this->assertLessThanOrEqual(10, count($ideas->json('data')));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/tiktok/clips?status=learned')
            ->assertOk()
            ->assertJsonPath('summary.learned', 1)
            ->assertJsonPath('summary.views', 10)
            ->assertJsonPath('data.0.views', 10)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/tiktok/clips?status=nope')->assertStatus(422);
        $this->assertSame($before, TikTokClip::query()->count());
    }

    private function readyUser(bool|int $allowance = 30): User
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
        if ($allowance !== false) {
            $this->giveTikTokAllowance($user, is_int($allowance) ? $allowance : 30);
        }

        return $user->fresh('accountProfile');
    }
}
