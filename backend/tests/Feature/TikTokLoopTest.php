<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\BillingSubscription;
use App\Models\ConnectedAccount;
use App\Models\PlatformSetting;
use App\Models\PlatformSetup;
use App\Models\Post;
use App\Models\RateLimitBudget;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\TikTok\TikTokAllowanceService;
use App\Services\TikTok\TikTokDemandService;
use App\Services\TikTok\TikTokScriptWriter;
use App\Support\TikTokCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class TikTokLoopTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openai.api_key' => '']);
        Http::preventStrayRequests();
    }

    public function test_confirm_queues_the_clip_and_a_second_confirm_does_not_stamp_again(): void
    {
        $user = $this->readyUser();
        $clip = $this->waitingClip($user);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', TikTokCatalog::QUEUED)
            ->assertJsonPath('data.publish_note', TikTokCatalog::CONFIRM_NOTE)
            ->assertJsonPath('data.blockers', [TikTokCatalog::BLOCKER_VIDEO_FILE, TikTokCatalog::BLOCKER_TIKTOK_CONNECTION]);

        $this->assertArrayNotHasKey('package', $first->json('data'));
        $clip->refresh();
        $this->assertFalse($clip->package['render']['ready']);
        $this->assertFalse($clip->package['publish']['uploaded']);
        $this->assertNotNull($clip->confirmed_at);
        $this->assertNotNull($clip->queued_at);
        $confirmedAt = $clip->confirmed_at->toIso8601String();
        $queuedAt = $clip->queued_at->toIso8601String();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
            ->assertOk();

        $clip->refresh();
        $this->assertSame($confirmedAt, $clip->confirmed_at->toIso8601String());
        $this->assertSame($queuedAt, $clip->queued_at->toIso8601String());
        $this->assertSame(1, TikTokClip::query()->where('status', TikTokCatalog::QUEUED)->count());
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, RateLimitBudget::query()->count());
    }

    public function test_confirm_rejects_the_wrong_state_another_user_and_a_blocked_account(): void
    {
        $user = $this->readyUser();
        foreach ([TikTokCatalog::DRAFTED, TikTokCatalog::LEARNED, TikTokCatalog::DISCARDED] as $state) {
            $clip = TikTokClip::factory()->{$this->stateName($state)}()->create(['user_id' => $user->id]);
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
                ->assertStatus(409)
                ->assertJsonPath('error.code', TikTokCatalog::ERROR_INVALID_STATE);
            $this->assertSame($state, $clip->fresh()->status);
        }

        $other = $this->readyUser();
        $theirs = $this->waitingClip($other);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$theirs->id.'/confirm')
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/tiktok/clips/'.$theirs->id.'/confirm')->assertUnauthorized();

        $blocked = $this->readyUser();
        $blocked->update(['is_blocked' => true]);
        $clip = $this->waitingClip($blocked);
        $this->actingAs($blocked, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
            ->assertForbidden();
    }

    public function test_allowance_and_the_daily_cap_stop_confirm_and_discard_frees_the_month(): void
    {
        $user = $this->readyUser(1);
        $first = $this->waitingClip($user);
        $second = $this->waitingClip($user);

        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$first->id.'/confirm')->assertOk();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$second->id.'/confirm')
            ->assertStatus(429)
            ->assertJsonPath('error.code', TikTokCatalog::ERROR_ALLOWANCE_SPENT);
        $this->assertSame(TikTokCatalog::WAITING, $second->fresh()->status);

        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$first->id.'/discard')->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$second->id.'/confirm')->assertOk();

        $capped = $this->readyUser(10);
        $this->setCap(1);
        $kept = $this->waitingClip($capped);
        $blocked = $this->waitingClip($capped);
        $this->actingAs($capped, 'sanctum')->postJson('/api/tiktok/clips/'.$kept->id.'/confirm')->assertOk();
        $this->actingAs($capped, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$blocked->id.'/confirm')
            ->assertStatus(429)
            ->assertJsonPath('error.code', TikTokCatalog::ERROR_DAILY_CAP);

        $kept->forceFill(['confirmed_at' => now()->subHours(25)])->save();
        $this->actingAs($capped, 'sanctum')->postJson('/api/tiktok/clips/'.$blocked->id.'/confirm')->assertOk();

        $again = $this->readyUser(10);
        $this->setCap(1);
        $one = $this->waitingClip($again);
        $two = $this->waitingClip($again);
        $this->actingAs($again, 'sanctum')->postJson('/api/tiktok/clips/'.$one->id.'/confirm')->assertOk();
        $this->actingAs($again, 'sanctum')->postJson('/api/tiktok/clips/'.$two->id.'/confirm')->assertStatus(429);
        $this->setCap(2);
        $this->actingAs($again, 'sanctum')->postJson('/api/tiktok/clips/'.$two->id.'/confirm')->assertOk();
    }

    public function test_allowance_counts_confirmed_clips_inside_the_current_period_only(): void
    {
        $user = $this->readyUser(8);
        $allowance = app(TikTokAllowanceService::class);

        TikTokClip::factory()->queued()->create(['user_id' => $user->id]);
        TikTokClip::factory()->posted()->create(['user_id' => $user->id]);
        TikTokClip::factory()->learned()->create([
            'user_id' => $user->id,
            'queued_at' => now()->subHour(),
            'confirmed_at' => now()->subHour(),
        ]);
        TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);
        TikTokClip::factory()->learned()->create(['user_id' => $user->id]);
        TikTokClip::factory()->queued()->create([
            'user_id' => $user->id,
            'queued_at' => now()->subMonths(2),
            'confirmed_at' => now()->subMonths(2),
        ]);

        $this->assertSame(3, $allowance->status($user)['used']);

        $queued = TikTokClip::query()->forUser($user)->where('status', TikTokCatalog::QUEUED)->first();
        $queued->transitionTo(TikTokCatalog::DISCARDED);
        $this->assertSame(2, $allowance->status($user)['used']);
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, RateLimitBudget::query()->count());
    }

    public function test_a_trial_resolves_a_line_and_a_missing_line_is_no_plan(): void
    {
        $trial = $this->readyUser();
        BillingSubscription::query()->where('user_id', $trial->id)->delete();
        $this->giveTikTokAllowance($trial, 8, 'trialing', 'monthly', now()->subDay(), now()->addDays(6));

        $status = app(TikTokAllowanceService::class)->status($trial);
        $this->assertTrue($status['plan_found']);
        $this->assertSame(8, $status['limit']);

        $bare = $this->readyUser();
        BillingSubscription::query()->where('user_id', $bare->id)->delete();
        $none = app(TikTokAllowanceService::class)->status($bare);
        $this->assertFalse($none['plan_found']);
        $this->assertFalse(app(TikTokAllowanceService::class)->canQueue($bare));

        $this->actingAs($bare, 'sanctum')
            ->postJson('/api/tiktok/run')
            ->assertOk()
            ->assertJsonPath('outcome', TikTokCatalog::SKIPPED)
            ->assertJsonPath('reason', TikTokCatalog::NO_PLAN)
            ->assertJsonPath('message', TikTokCatalog::noPlanMessage());
    }

    public function test_a_yearly_plan_still_counts_a_month_at_a_time(): void
    {
        $user = User::factory()->create();
        $start = now()->utc()->subMonths(2);
        $this->giveTikTokAllowance($user, 8, 'active', 'yearly', $start, $start->copy()->addYear());
        $periodStart = CarbonImmutable::parse(app(TikTokAllowanceService::class)->status($user)['period_start']);

        TikTokClip::factory()->queued()->create([
            'user_id' => $user->id,
            'queued_at' => $periodStart->subDay(),
            'confirmed_at' => $periodStart->subDay(),
        ]);
        TikTokClip::factory()->queued()->create([
            'user_id' => $user->id,
            'queued_at' => $periodStart->addHour(),
            'confirmed_at' => $periodStart->addHour(),
        ]);

        $this->assertSame(1, app(TikTokAllowanceService::class)->status($user)['used']);
    }

    public function test_results_learn_the_clip_and_prepare_the_next_one(): void
    {
        $user = $this->readyUser();
        $clip = $this->waitingClip($user, TikTokCatalog::OPINION);
        $seen = null;
        $this->mock(TikTokScriptWriter::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('clip')->andReturnUsing(function ($user, $profile, $idea, $note) use (&$seen) {
                $seen ??= $note;

                return $this->script();
            });
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/results', [
                'views' => 120,
                'completion_percent' => 50,
                'follows' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TikTokCatalog::LEARNED)
            ->assertJsonPath('next.outcome', TikTokCatalog::CREATED);

        $clip->refresh();
        $this->assertSame(120, $clip->views);
        $this->assertSame(4, $clip->follows);
        $this->assertStringContainsString('held', (string) $clip->learning_note);
        $this->assertStringContainsString('opinion', (string) $clip->learning_note);
        $this->assertStringContainsString('Lean into this kind of idea next.', (string) $clip->learning_note);
        $this->assertSame($clip->learning_note, $seen);
        $this->assertSame(TikTokCatalog::WAITING, TikTokClip::query()->forUser($user)->where('status', TikTokCatalog::WAITING)->value('status'));

        foreach ([30 => 'just recorded', 29 => 'left early', 80 => 'held'] as $percent => $band) {
            $another = $this->readyUser();
            $row = $this->waitingClip($another, TikTokCatalog::TIP);
            $this->actingAs($another, 'sanctum')->postJson('/api/tiktok/clips/'.$row->id.'/results', [
                'views' => 1,
                'completion_percent' => $percent,
                'follows' => 0,
            ])->assertOk();
            $this->assertStringContainsString($band, (string) $row->fresh()->learning_note);
        }
    }

    public function test_results_from_queued_and_posted_work_and_a_second_save_is_rejected(): void
    {
        $user = $this->readyUser();
        foreach (['queued', 'posted'] as $state) {
            $clip = TikTokClip::factory()->{$state}()->create([
                'user_id' => $user->id,
                'package' => ['idea_kind' => TikTokCatalog::STORY, 'render' => TikTokCatalog::defaultRender(), 'publish' => TikTokCatalog::defaultPublish()],
            ]);
            $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$clip->id.'/results', [
                'views' => 10,
                'completion_percent' => 40,
                'follows' => 1,
            ])->assertOk()->assertJsonPath('data.status', TikTokCatalog::LEARNED);
        }

        $learned = TikTokClip::factory()->learned()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$learned->id.'/results', [
                'views' => 10,
                'completion_percent' => 40,
                'follows' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', TikTokCatalog::ERROR_ALREADY_LEARNED);

        $draft = TikTokClip::factory()->drafted()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$draft->id.'/results', [
                'views' => 10,
                'completion_percent' => 40,
                'follows' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', TikTokCatalog::ERROR_INVALID_STATE);
    }

    public function test_a_skipped_or_failing_next_run_does_not_undo_results(): void
    {
        $spent = $this->readyUser(0);
        $clip = $this->waitingClip($spent);
        $this->actingAs($spent, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/results', $this->resultsBody())
            ->assertOk()
            ->assertJsonPath('data.status', TikTokCatalog::LEARNED)
            ->assertJsonPath('next.outcome', TikTokCatalog::SKIPPED)
            ->assertJsonPath('next.reason', TikTokCatalog::ALLOWANCE_SPENT);
        $this->assertSame(TikTokCatalog::LEARNED, $clip->fresh()->status);
    }

    public function test_a_throwing_next_run_does_not_undo_results(): void
    {
        $user = $this->readyUser();
        $row = $this->waitingClip($user);
        $this->mock(TikTokDemandService::class, function ($mock) {
            $mock->shouldReceive('ranked')->andThrow(new \RuntimeException('down'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$row->id.'/results', $this->resultsBody())
            ->assertOk()
            ->assertJsonPath('data.status', TikTokCatalog::LEARNED)
            ->assertJsonPath('next', null);
        $this->assertSame(TikTokCatalog::LEARNED, $row->fresh()->status);
    }

    public function test_results_validation_rejects_bad_numbers(): void
    {
        $user = $this->readyUser();
        $clip = $this->waitingClip($user);
        $url = '/api/tiktok/clips/'.$clip->id.'/results';

        $this->actingAs($user, 'sanctum')->postJson($url, ['views' => -1, 'completion_percent' => 10, 'follows' => 0])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson($url, ['views' => 1, 'completion_percent' => 101, 'follows' => 0])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson($url, ['views' => 'many', 'completion_percent' => 10, 'follows' => 0])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson($url, [])->assertStatus(422);
        $this->assertSame(TikTokCatalog::WAITING, $clip->fresh()->status);
    }

    public function test_discard_and_reschedule(): void
    {
        $user = $this->readyUser();
        $waiting = $this->waitingClip($user);
        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$waiting->id.'/discard')->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$waiting->id.'/discard')->assertOk();
        $this->assertSame(TikTokCatalog::DISCARDED, $waiting->fresh()->status);

        $run = $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/run')->assertCreated();
        $this->assertSame(TikTokCatalog::WAITING, $run->json('clip.status'));

        $queued = TikTokClip::factory()->queued()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$queued->id.'/discard')->assertOk();

        $learned = TikTokClip::factory()->learned()->create(['user_id' => $user->id]);
        $posted = TikTokClip::factory()->posted()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$learned->id.'/discard')->assertStatus(409);
        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/clips/'.$posted->id.'/discard')->assertStatus(409);
    }

    public function test_reschedule_converts_an_offset_and_rejects_bad_times(): void
    {
        $this->travelTo('2026-10-27 12:00:00');
        $user = $this->readyUser();
        $clip = $this->waitingClip($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/reschedule', [
                'suggested_for' => '2026-10-28T19:30:00+03:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', TikTokCatalog::WAITING)
            ->assertJsonPath('data.suggested_for', '2026-10-28T16:30:00+00:00');
        $this->assertTrue($clip->fresh()->package['rescheduled_by_user']);

        $tooSoon = now()->addMinutes(30)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/reschedule', ['suggested_for' => $tooSoon])
            ->assertStatus(422)
            ->assertJsonPath('error.code', TikTokCatalog::ERROR_INVALID_TIME);

        $past = '2026-10-26T19:30:00Z';
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/reschedule', ['suggested_for' => $past])
            ->assertStatus(422);

        $far = now()->addDays(15)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/reschedule', ['suggested_for' => $far])
            ->assertStatus(422)
            ->assertJsonPath('error.code', TikTokCatalog::ERROR_INVALID_TIME);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/reschedule', ['suggested_for' => 'tomorrow'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', TikTokCatalog::ERROR_VALIDATION);

        $queued = TikTokClip::factory()->queued()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$queued->id.'/reschedule', [
                'suggested_for' => '2026-10-28T19:30:00Z',
            ])
            ->assertStatus(409);
    }

    public function test_run_skip_reasons_have_human_messages_and_the_route_is_throttled(): void
    {
        $user = $this->readyUser();
        $this->actingAs($user, 'sanctum')->postJson('/api/tiktok/run')->assertCreated();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/run')
            ->assertOk()
            ->assertJsonPath('reason', TikTokCatalog::OPEN_CLIP)
            ->assertJsonPath('message', TikTokCatalog::skipMessage(TikTokCatalog::OPEN_CLIP));

        $fresh = User::factory()->create();
        $this->actingAs($fresh, 'sanctum')
            ->postJson('/api/tiktok/run')
            ->assertOk()
            ->assertJsonPath('reason', TikTokCatalog::NOT_ONBOARDED);

        $onboarded = $this->readyUser();
        PlatformSetup::query()->where('user_id', $onboarded->id)->delete();
        $this->actingAs($onboarded, 'sanctum')
            ->postJson('/api/tiktok/run')
            ->assertOk()
            ->assertJsonPath('reason', TikTokCatalog::SETUP_INCOMPLETE);

        $spent = $this->readyUser(1);
        TikTokClip::factory()->learned()->create([
            'user_id' => $spent->id,
            'queued_at' => now()->subHour(),
            'confirmed_at' => now()->subHour(),
        ]);
        $this->actingAs($spent, 'sanctum')
            ->postJson('/api/tiktok/run')
            ->assertOk()
            ->assertJsonPath('reason', TikTokCatalog::ALLOWANCE_SPENT)
            ->assertJsonPath('message', TikTokCatalog::allowanceSpentMessage(1, substr(app(TikTokAllowanceService::class)->status($spent)['period_end'], 0, 10)));

        $locked = $this->readyUser();
        $lock = Cache::lock('tiktok-run-'.$locked->id, 30);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($locked, 'sanctum')
                ->postJson('/api/tiktok/run')
                ->assertOk()
                ->assertJsonPath('reason', TikTokCatalog::BUSY)
                ->assertJsonPath('message', TikTokCatalog::skipMessage(TikTokCatalog::BUSY));
        } finally {
            $lock->release();
        }

        $throttled = $this->readyUser();
        PlatformSetup::query()->where('user_id', $throttled->id)->update(['completed_at' => null]);
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($throttled, 'sanctum')->postJson('/api/tiktok/run')->assertOk();
        }
        $this->actingAs($throttled, 'sanctum')->postJson('/api/tiktok/run')->assertStatus(429);
    }

    public function test_blockers_clear_only_when_the_file_and_the_connection_exist(): void
    {
        $user = $this->readyUser();
        $clip = $this->waitingClip($user);
        $clip->update([
            'package' => array_merge($clip->package, [
                'render' => ['provider' => 'pending', 'ready' => true],
            ]),
        ]);
        ConnectedAccount::query()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'username' => 'cook',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/tiktok/clips/'.$clip->id.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.blockers', []);
    }

    public function test_the_daily_cap_is_an_admin_setting(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/platform-settings', [
                'settings' => [['key' => 'tiktok_daily_clip_cap', 'value' => 9]],
            ])
            ->assertOk();

        $this->assertSame(9, app(PlatformSettingsService::class)->tiktokDailyClipCap());

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/platform-settings', [
                'settings' => [['key' => 'tiktok_daily_clip_cap', 'value' => 80]],
            ])
            ->assertOk();
        $this->assertSame(50, app(PlatformSettingsService::class)->tiktokDailyClipCap());
    }

    private function readyUser(int $allowance = 30): User
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
        $this->giveTikTokAllowance($user, $allowance);

        return $user;
    }

    private function waitingClip(User $user, string $kind = TikTokCatalog::OPINION): TikTokClip
    {
        return TikTokClip::factory()->waiting()->create([
            'user_id' => $user->id,
            'package' => [
                'idea_kind' => $kind,
                'shots' => ['Pot on the stove', 'Salt in the hand', 'Pasta going in'],
                'on_screen_text' => ['Salt it first'],
                'sound_note' => 'Quiet kitchen',
                'timezone' => 'Africa/Kampala',
                'timezone_assumed' => false,
                'render' => TikTokCatalog::defaultRender(),
                'publish' => TikTokCatalog::defaultPublish(),
            ],
        ]);
    }

    private function stateName(string $status): string
    {
        return match ($status) {
            TikTokCatalog::DRAFTED => 'drafted',
            TikTokCatalog::LEARNED => 'learned',
            TikTokCatalog::DISCARDED => 'discarded',
            default => 'waiting',
        };
    }

    private function setCap(int $cap): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'tiktok_daily_clip_cap'],
            ['value' => (string) $cap],
        );
        app(PlatformSettingsService::class)->clearCache();
    }

    /**
     * @return array{views: int, completion_percent: int, follows: int}
     */
    private function resultsBody(): array
    {
        return ['views' => 10, 'completion_percent' => 40, 'follows' => 1];
    }

    /**
     * @return array{topic: string, idea_kind: string, hook: string, spoken_lines: list<string>, caption: string, on_screen_text: list<string>, shots: list<string>, sound_note: string, source: string}
     */
    private function script(): array
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
            'on_screen_text' => ['Salt it first'],
            'shots' => ['Pot on the stove', 'Salt in the hand', 'Pasta going in'],
            'sound_note' => 'Quiet kitchen',
            'source' => TikTokCatalog::SOURCE_FALLBACK,
        ];
    }
}
