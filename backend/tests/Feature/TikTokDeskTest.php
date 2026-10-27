<?php

namespace Tests\Feature;

use App\Models\AccountProfile;
use App\Models\ConnectedAccount;
use App\Models\PlatformSetting;
use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\TikTok\TikTokEngineService;
use App\Support\TikTokCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\GivesTikTokAllowance;
use Tests\TestCase;

class TikTokDeskTest extends TestCase
{
    use GivesTikTokAllowance;
    use RefreshDatabase;

    public function test_the_desk_is_read_only_and_describes_the_current_clip(): void
    {
        config(['openai.api_key' => '']);
        $user = $this->readyUser();
        $waiting = TikTokClip::factory()->waiting()->create([
            'user_id' => $user->id,
            'package' => $this->package(assumed: true),
        ]);
        TikTokClip::factory()->learned()->create([
            'user_id' => $user->id,
            'learning_note' => 'This question clip held.',
            'topic' => 'An older question',
        ]);
        TikTokClip::factory()->discarded()->create(['user_id' => $user->id]);
        $before = TikTokClip::query()->count();
        $updated = $waiting->fresh()->updated_at?->toIso8601String();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('tiktok.clip.id', $waiting->id)
            ->assertJsonPath('tiktok.clip.status', TikTokCatalog::WAITING)
            ->assertJsonPath('tiktok.recent.0.learning_note', 'This question clip held.')
            ->assertJsonPath('tiktok.pipeline.waiting', 1)
            ->assertJsonPath('tiktok.pipeline.written', 2)
            ->assertJsonPath('tiktok.block', null)
            ->assertJsonPath('tiktok.timezone.value', 'Africa/Kampala')
            ->assertJsonPath('tiktok.timezone.assumed', false)
            ->assertJsonPath('tiktok.connection.connected', false)
            ->assertJsonPath('tiktok.allowance.plan_found', true)
            ->assertJsonPath('preview', true)
            ->assertJsonPath('pipeline', []);

        $idea = $response->json('tiktok.ideas.0');
        $this->assertIsArray($idea);
        $this->assertArrayNotHasKey('score', $idea);
        $this->assertArrayHasKey('kind_label', $idea);
        $this->assertArrayNotHasKey('package', $response->json('tiktok.clip'));
        $this->assertSame($before, TikTokClip::query()->count());
        $this->assertSame($updated, $waiting->fresh()->updated_at?->toIso8601String());
        $this->assertSame(0, $user->posts()->count());
    }

    public function test_block_explains_a_missing_plan_or_a_spent_month_only_when_no_clip_is_open(): void
    {
        $open = $this->readyUser(allowance: false);
        $this->actingAs($open, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('tiktok.clip', null)
            ->assertJsonPath('tiktok.block.reason', TikTokCatalog::NO_PLAN)
            ->assertJsonPath('tiktok.block.message', TikTokCatalog::skipMessage(TikTokCatalog::NO_PLAN))
            ->assertJsonPath('tiktok.block.action', 'billing')
            ->assertJsonPath('tiktok.allowance.period_end', null);

        $spent = $this->readyUser(allowance: 1);
        TikTokClip::factory()->learned()->create([
            'user_id' => $spent->id,
            'queued_at' => now()->subHour(),
            'confirmed_at' => now()->subHour(),
        ]);
        $this->actingAs($spent, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('tiktok.clip', null)
            ->assertJsonPath('tiktok.block.reason', TikTokCatalog::ALLOWANCE_SPENT)
            ->assertJsonPath('tiktok.block.action', 'wait')
            ->assertJsonPath('tiktok.allowance.remaining', 0);

        $busy = $this->readyUser(allowance: 1);
        TikTokClip::factory()->learned()->create([
            'user_id' => $busy->id,
            'queued_at' => now()->subHour(),
            'confirmed_at' => now()->subHour(),
        ]);
        $waiting = TikTokClip::factory()->waiting()->create(['user_id' => $busy->id]);
        $this->actingAs($busy, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('tiktok.clip.id', $waiting->id)
            ->assertJsonPath('tiktok.block', null);
    }

    public function test_daily_cap_matches_confirm_and_a_discard_frees_the_slot(): void
    {
        $this->setCap(1);
        $user = $this->readyUser();
        TikTokClip::factory()->learned()->create([
            'user_id' => $user->id,
            'confirmed_at' => now()->subHour(),
            'queued_at' => now()->subHour(),
        ]);
        TikTokClip::factory()->waiting()->create(['user_id' => $user->id]);

        $reached = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('tiktok.daily_cap.cap', 1)
            ->assertJsonPath('tiktok.daily_cap.used', 1)
            ->assertJsonPath('tiktok.daily_cap.reached', true);
        $this->assertNotNull($reached->json('tiktok.daily_cap.available_at'));

        TikTokClip::query()->where('user_id', $user->id)->where('status', TikTokCatalog::LEARNED)->update([
            'status' => TikTokCatalog::DISCARDED,
            'discarded_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('tiktok.daily_cap.used', 0)
            ->assertJsonPath('tiktok.daily_cap.reached', false)
            ->assertJsonPath('tiktok.daily_cap.available_at', null);
    }

    public function test_a_saved_timezone_refreshes_only_an_assumed_waiting_clip(): void
    {
        $this->travelTo('2026-10-27 12:00:00');
        $user = $this->readyUser(timezone: null);
        $waiting = TikTokClip::factory()->waiting()->create([
            'user_id' => $user->id,
            'suggested_for' => now()->addHours(2),
            'package' => $this->package(assumed: true),
        ]);
        $queued = TikTokClip::factory()->queued()->create([
            'user_id' => $user->id,
            'package' => $this->package(assumed: true),
        ]);
        $queuedAt = $queued->suggested_for?->toIso8601String();
        $pickedUser = $this->readyUser(timezone: null);
        $picked = TikTokClip::factory()->waiting()->create([
            'user_id' => $pickedUser->id,
            'suggested_for' => now()->addHours(5),
            'package' => $this->package(assumed: true, rescheduled: true),
        ]);
        $pickedAt = $picked->suggested_for?->toIso8601String();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/account/timezone', ['timezone' => 'Africa/Kampala'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Africa/Kampala')
            ->assertJsonPath('data.assumed', false);

        $waiting->refresh();
        $this->assertSame('Africa/Kampala', $waiting->package['timezone']);
        $this->assertFalse($waiting->package['timezone_assumed']);
        $this->assertSame('2026-10-27 16:30:00', $waiting->suggested_for?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($queuedAt, $queued->fresh()->suggested_for?->toIso8601String());

        $this->actingAs($pickedUser, 'sanctum')
            ->putJson('/api/account/timezone', ['timezone' => 'Africa/Kampala'])
            ->assertOk();
        $this->assertSame($pickedAt, $picked->fresh()->suggested_for?->toIso8601String());
        $this->assertTrue($picked->fresh()->package['timezone_assumed']);
        $this->assertTrue($picked->fresh()->package['rescheduled_by_user']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/account/timezone', ['timezone' => 'Not/AZone'])
            ->assertStatus(422);
    }

    public function test_finishing_tiktok_setup_prepares_one_clip_and_a_failure_does_not_undo_setup(): void
    {
        config(['openai.api_key' => '']);
        $user = User::factory()->create();
        $api = $this->actingAs($user, 'sanctum');
        $api->postJson('/api/account/setup/platforms', ['platforms' => ['tiktok']])->assertOk();
        $api->postJson('/api/account/setup/about', [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => ['funny'],
        ])->assertOk();
        $this->giveTikTokAllowance($user);

        $api->postJson('/api/account/setup/platforms/tiktok', [
            'goals' => ['for_you'],
            'timezone' => 'Africa/Kampala',
        ])->assertOk()->assertJsonPath('setups.tiktok.completed', true);

        $this->assertSame('Africa/Kampala', $user->fresh()->accountProfile->timezone);
        $this->assertSame(1, TikTokClip::query()->where('user_id', $user->id)->count());
        $clip = TikTokClip::query()->where('user_id', $user->id)->first();
        $this->assertSame(TikTokCatalog::WAITING, $clip->status);
        $this->assertFalse($clip->package['render']['ready']);
        $this->assertFalse($clip->package['publish']['uploaded']);
        $this->assertSame('Africa/Kampala', $clip->package['timezone']);
        $this->assertFalse($clip->package['timezone_assumed']);

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum');
        $this->postJson('/api/account/setup/platforms', ['platforms' => ['tiktok']])->assertOk();
        $this->postJson('/api/account/setup/about', [
            'purpose' => 'creator',
            'custom_niche' => 'Budget home cooking',
            'description' => 'Cheap weeknight meals for one, with honest shortcuts.',
            'audience' => 'Students cooking on a budget',
            'tone' => ['funny'],
        ])->assertOk();
        $this->mock(TikTokEngineService::class, function ($mock) {
            $mock->shouldReceive('refreshAssumedTime')->andReturn(null);
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('down'));
        });

        $this->postJson('/api/account/setup/platforms/tiktok', [
            'goals' => ['followers'],
            'timezone' => 'Not/AZone',
        ])->assertOk()->assertJsonPath('onboarding_completed', true);

        $this->assertNull($other->fresh()->accountProfile->timezone);
        $this->assertSame(0, TikTokClip::query()->where('user_id', $other->id)->count());
    }

    public function test_an_active_tiktok_account_clears_the_connection_flag_only(): void
    {
        $user = $this->readyUser();
        ConnectedAccount::query()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'username' => 'kitchen',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/platform/tiktok')
            ->assertOk()
            ->assertJsonPath('tiktok.connection.connected', true)
            ->assertJsonPath('connected.username', 'kitchen')
            ->assertJsonPath('preview', false);
    }

    private function readyUser(bool|int $allowance = 30, ?string $timezone = 'Africa/Kampala'): User
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
            'timezone' => $timezone,
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

    /**
     * @return array<string, mixed>
     */
    private function package(bool $assumed, bool $rescheduled = false): array
    {
        return [
            'on_screen_text' => ['Salt it first'],
            'shots' => ['Pot on the stove'],
            'sound_note' => 'Quiet kitchen',
            'timezone' => 'UTC',
            'timezone_assumed' => $assumed,
            'rescheduled_by_user' => $rescheduled,
            'render' => TikTokCatalog::defaultRender(),
            'publish' => TikTokCatalog::defaultPublish(),
        ];
    }

    private function setCap(int $cap): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => 'tiktok_daily_clip_cap'],
            ['value' => (string) $cap],
        );
        app(PlatformSettingsService::class)->clearCache();
    }
}
