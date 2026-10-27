<?php

namespace Tests\Feature;

use App\Exceptions\InvalidTikTokTransition;
use App\Models\TikTokClip;
use App\Models\User;
use App\Support\TikTokCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TikTokClipTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_default_is_a_valid_waiting_clip(): void
    {
        $clip = TikTokClip::factory()->create();

        $this->assertSame(TikTokCatalog::WAITING, $clip->status);
        $this->assertTrue($clip->suggested_for->isFuture());
        $this->assertLessThanOrEqual(400, mb_strlen($clip->caption));
        $this->assertSame(3, preg_match_all('/#\w+/', $clip->caption));
        $this->assertFalse($clip->package['render']['ready']);
        $this->assertFalse($clip->package['publish']['uploaded']);
        $this->assertNull($clip->confirmed_at);
        $this->assertNull($clip->queued_at);
        $this->assertNull($clip->posted_at);
        $this->assertNull($clip->learned_at);
        $this->assertNull($clip->discarded_at);
    }

    public function test_each_named_state_persists_the_timestamps_that_status_implies(): void
    {
        $drafted = TikTokClip::factory()->drafted()->create();
        $this->assertSame(TikTokCatalog::DRAFTED, $drafted->status);
        $this->assertNull($drafted->confirmed_at);
        $this->assertNull($drafted->queued_at);
        $this->assertNull($drafted->posted_at);
        $this->assertNull($drafted->learned_at);
        $this->assertNull($drafted->discarded_at);

        $waiting = TikTokClip::factory()->waiting()->create();
        $this->assertSame(TikTokCatalog::WAITING, $waiting->status);
        $this->assertTrue($waiting->suggested_for->isFuture());
        $this->assertNull($waiting->confirmed_at);
        $this->assertNull($waiting->queued_at);

        $queued = TikTokClip::factory()->queued()->create();
        $this->assertNotNull($queued->confirmed_at);
        $this->assertNotNull($queued->queued_at);
        $this->assertNull($queued->posted_at);
        $this->assertNull($queued->learned_at);
        $this->assertNull($queued->discarded_at);

        $posted = TikTokClip::factory()->posted()->create();
        $this->assertNotNull($posted->confirmed_at);
        $this->assertNotNull($posted->queued_at);
        $this->assertNotNull($posted->posted_at);
        $this->assertNull($posted->learned_at);
        $this->assertNull($posted->discarded_at);

        $learned = TikTokClip::factory()->learned()->create();
        $this->assertNotNull($learned->learned_at);
        $this->assertNull($learned->discarded_at);

        $discarded = TikTokClip::factory()->discarded()->create();
        $this->assertNotNull($discarded->discarded_at);
        $this->assertNull($discarded->learned_at);
        $this->assertNull($discarded->posted_at);
    }

    public function test_every_legal_transition_stamps_only_the_new_timestamp(): void
    {
        $confirmedAt = now()->subHour()->startOfSecond();

        $cases = [
            [TikTokCatalog::DRAFTED, TikTokCatalog::WAITING, 'drafted'],
            [TikTokCatalog::DRAFTED, TikTokCatalog::DISCARDED, 'drafted'],
            [TikTokCatalog::WAITING, TikTokCatalog::QUEUED, 'waiting'],
            [TikTokCatalog::WAITING, TikTokCatalog::LEARNED, 'waiting'],
            [TikTokCatalog::WAITING, TikTokCatalog::DISCARDED, 'waiting'],
            [TikTokCatalog::QUEUED, TikTokCatalog::POSTED, 'queued'],
            [TikTokCatalog::QUEUED, TikTokCatalog::LEARNED, 'queued'],
            [TikTokCatalog::QUEUED, TikTokCatalog::DISCARDED, 'queued'],
            [TikTokCatalog::POSTED, TikTokCatalog::LEARNED, 'posted'],
        ];

        foreach ($cases as [$from, $to, $factory]) {
            $clip = TikTokClip::factory()->{$factory}()->create(
                $from === TikTokCatalog::QUEUED ? ['confirmed_at' => $confirmedAt, 'queued_at' => $confirmedAt] : []
            );

            $clip->transitionTo($to);

            $this->assertSame($to, $clip->fresh()->status, "{$from} → {$to}");

            if ($to === TikTokCatalog::QUEUED) {
                $this->assertNotNull($clip->confirmed_at);
                $this->assertNotNull($clip->queued_at);
            }
            if ($to === TikTokCatalog::POSTED) {
                $this->assertTrue($clip->confirmed_at->equalTo($confirmedAt));
                $this->assertTrue($clip->queued_at->equalTo($confirmedAt));
                $this->assertNotNull($clip->posted_at);
            }
            if ($to === TikTokCatalog::LEARNED) {
                $this->assertNotNull($clip->learned_at);
            }
            if ($to === TikTokCatalog::DISCARDED) {
                $this->assertNotNull($clip->discarded_at);
            }
            if ($from === TikTokCatalog::QUEUED && $to === TikTokCatalog::LEARNED) {
                $this->assertTrue($clip->confirmed_at->equalTo($confirmedAt));
                $this->assertNull($clip->posted_at);
            }
        }
    }

    public function test_illegal_transitions_name_both_states_and_leave_the_row(): void
    {
        $illegal = [
            [TikTokCatalog::WAITING, TikTokCatalog::POSTED, 'waiting'],
            [TikTokCatalog::DRAFTED, TikTokCatalog::QUEUED, 'drafted'],
            [TikTokCatalog::LEARNED, TikTokCatalog::WAITING, 'learned'],
            [TikTokCatalog::DISCARDED, TikTokCatalog::WAITING, 'discarded'],
            [TikTokCatalog::POSTED, TikTokCatalog::QUEUED, 'posted'],
            [TikTokCatalog::WAITING, TikTokCatalog::WAITING, 'waiting'],
            [TikTokCatalog::LEARNED, TikTokCatalog::LEARNED, 'learned'],
            [TikTokCatalog::QUEUED, TikTokCatalog::WAITING, 'queued'],
        ];

        foreach ($illegal as [$from, $to, $factory]) {
            $clip = TikTokClip::factory()->{$factory}()->create();

            try {
                $clip->transitionTo($to);
                $this->fail("Expected {$from} → {$to} to throw.");
            } catch (InvalidTikTokTransition $e) {
                $this->assertStringContainsString($from, $e->getMessage());
                $this->assertStringContainsString($to, $e->getMessage());
            }

            $this->assertSame($from, $clip->fresh()->status);
        }
    }

    public function test_a_second_confirm_reads_the_locked_row_instead_of_the_stale_copy(): void
    {
        $first = TikTokClip::factory()->waiting()->create();
        $stale = TikTokClip::query()->findOrFail($first->id);

        $first->transitionTo(TikTokCatalog::QUEUED);

        $this->expectException(InvalidTikTokTransition::class);
        $stale->transitionTo(TikTokCatalog::QUEUED);
    }

    public function test_scopes_separate_open_blocking_and_users(): void
    {
        $ada = User::factory()->create();
        $ben = User::factory()->create();

        TikTokClip::factory()->drafted()->create(['user_id' => $ada->id]);
        TikTokClip::factory()->waiting()->create(['user_id' => $ada->id]);
        TikTokClip::factory()->queued()->create(['user_id' => $ada->id]);
        TikTokClip::factory()->posted()->create(['user_id' => $ada->id]);
        TikTokClip::factory()->learned()->create(['user_id' => $ada->id]);
        TikTokClip::factory()->discarded()->create(['user_id' => $ada->id]);
        TikTokClip::factory()->waiting()->create(['user_id' => $ben->id]);

        $this->assertSame(2, TikTokClip::query()->forUser($ada)->open()->count());
        $this->assertSame(4, TikTokClip::query()->forUser($ada)->blocking()->count());
        $this->assertSame(1, TikTokClip::query()->forUser($ben)->count());
        $this->assertSame(1, TikTokClip::query()->forUser($ben->id)->open()->count());
        $this->assertSame(2, TikTokClip::query()->forUser($ada)->withStatus(TikTokCatalog::POSTED, TikTokCatalog::LEARNED)->count());
    }

    public function test_a_clip_with_no_package_still_reports_render_and_publish_as_unsent(): void
    {
        $clip = TikTokClip::factory()->create(['package' => null]);

        $this->assertFalse($clip->package['render']['ready']);
        $this->assertFalse($clip->package['publish']['uploaded']);
        $this->assertSame('pending', $clip->package['render']['provider']);
        $this->assertSame('confirm', $clip->package['publish']['mode']);
        $this->assertFalse($clip->packageWithDefaults()['render']['ready']);
        $this->assertSame('Confirmed, not posted yet', TikTokClip::factory()->queued()->make()->statusLabel());
    }

    public function test_deleting_a_user_deletes_their_clips(): void
    {
        $user = User::factory()->create();
        $clip = TikTokClip::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertDatabaseMissing('tiktok_clips', ['id' => $clip->id]);
    }
}
