<?php

namespace App\Services\TikTok;

use App\Exceptions\TikTokActionException;
use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Support\TikTokCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TikTokEngineService
{
    public function __construct(
        private readonly TikTokDemandService $demand,
        private readonly TikTokScriptWriter $scripts,
        private readonly TikTokTimeSuggester $times,
        private readonly TikTokAllowanceService $allowance,
        private readonly PlatformSettingsService $settings,
    ) {}

    /**
     * @return array{outcome: string, clip: TikTokClip}|array{outcome: string, reason: string}
     */
    public function run(User $user): array
    {
        $lock = Cache::lock('tiktok-run-'.$user->id, 30);
        if (! $lock->get()) {
            return $this->skip($user, TikTokCatalog::BUSY);
        }

        try {
            return $this->runUnlocked($user);
        } finally {
            $lock->release();
        }
    }

    /**
     * Allowance is a count of confirmed clips, not a stored balance.
     * null means the user can have another clip. Otherwise the value is the skip reason.
     */
    private function allowanceBlock(User $user): ?string
    {
        $status = $this->allowance->status($user);
        if (! $status['plan_found']) {
            return TikTokCatalog::NO_PLAN;
        }

        if (! $this->allowance->canQueue($user)) {
            return TikTokCatalog::ALLOWANCE_SPENT;
        }

        return null;
    }

    /**
     * @return array{outcome: string, clip: TikTokClip}|array{outcome: string, reason: string}
     */
    private function runUnlocked(User $user): array
    {
        if ($user->is_blocked) {
            return $this->skip($user, TikTokCatalog::BLOCKED);
        }

        $profile = $user->accountProfile;
        if (! $profile || ! $profile->onboarding_completed) {
            return $this->skip($user, TikTokCatalog::NOT_ONBOARDED);
        }

        $setup = PlatformSetup::query()
            ->where('user_id', $user->id)
            ->where('platform', 'tiktok')
            ->first();
        if (! $setup || $setup->completed_at === null) {
            return $this->skip($user, TikTokCatalog::SETUP_INCOMPLETE);
        }

        if (TikTokClip::query()->forUser($user)->blocking()->exists()) {
            return $this->skip($user, TikTokCatalog::OPEN_CLIP);
        }

        $allowanceBlock = $this->allowanceBlock($user);
        if ($allowanceBlock !== null) {
            return $this->skip($user, $allowanceBlock);
        }

        $ideas = $this->demand->ranked($user);
        $idea = $ideas[0] ?? null;
        if ($idea === null) {
            throw new \RuntimeException('TikTok demand returned no ideas.');
        }

        $learningNote = TikTokClip::query()
            ->forUser($user)
            ->where('status', TikTokCatalog::LEARNED)
            ->whereNotNull('learning_note')
            ->where('learning_note', '!=', '')
            ->latest('id')
            ->value('learning_note');
        $learningNote = is_string($learningNote) && $learningNote !== '' ? $learningNote : null;

        try {
            $script = $this->scripts->clip($user, $profile, $idea, $learningNote);
        } catch (Throwable $e) {
            Log::warning('TikTok script fell back to the local writer', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $script = $this->scripts->fallback($user, $profile, $idea, $learningNote);
        }

        $when = $this->times->next($profile->timezone, now());
        $package = [
            'idea_kind' => $script['idea_kind'],
            'goal_keys' => $profile->goalsFor('tiktok'),
            'shots' => $script['shots'],
            'on_screen_text' => $script['on_screen_text'],
            'sound_note' => $script['sound_note'],
            'source' => $script['source'],
            'timezone' => $when['timezone'],
            'timezone_assumed' => $when['assumed'],
            'render' => TikTokCatalog::defaultRender(),
            'publish' => TikTokCatalog::defaultPublish(),
        ];

        try {
            $clip = DB::transaction(function () use ($user, $script, $when, $package, $idea) {
                // The cache lock stops a second run. This row lock stops a clip that landed
                // between the earlier check and the insert. waiting is not posted.
                if (TikTokClip::query()->forUser($user)->blocking()->lockForUpdate()->exists()) {
                    return null;
                }

                $created = TikTokClip::query()->create([
                    'user_id' => $user->id,
                    'status' => TikTokCatalog::DRAFTED,
                    'topic' => $script['topic'],
                    'hook' => $script['hook'],
                    'script' => implode("\n", $script['spoken_lines']),
                    'caption' => $script['caption'],
                    'suggested_for' => $when['at'],
                    'score' => $idea['score'],
                    'package' => $package,
                ]);

                return $created->transitionTo(TikTokCatalog::WAITING);
            });
        } catch (QueryException $e) {
            Log::error('TikTok engine failed to store a clip', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($clip === null) {
            return $this->skip($user, TikTokCatalog::OPEN_CLIP);
        }

        Log::info('TikTok engine created a clip', [
            'user_id' => $user->id,
            'clip_id' => $clip->id,
            'outcome' => TikTokCatalog::CREATED,
        ]);

        return ['outcome' => TikTokCatalog::CREATED, 'clip' => $clip];
    }

    /**
     * @return array{outcome: string, reason: string}
     */
    private function skip(User $user, string $reason): array
    {
        Log::info('TikTok engine skipped', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);

        return ['outcome' => TikTokCatalog::SKIPPED, 'reason' => $reason];
    }

    public function confirm(User $user, TikTokClip $clip): TikTokClip
    {
        $this->owned($user, $clip);

        return DB::transaction(function () use ($user, $clip) {
            $fresh = $this->lock($clip);
            if ($fresh->status === TikTokCatalog::QUEUED) {
                return $fresh;
            }
            if ($fresh->status !== TikTokCatalog::WAITING) {
                throw new TikTokActionException(
                    TikTokCatalog::ERROR_INVALID_STATE,
                    'This clip is not waiting for a yes. Skip it, or wait for the next one.',
                );
            }

            // Allowance first, then the rolling daily cap. The clip itself is not counted.
            $this->assertCanConfirm($user, $fresh);

            $queued = $fresh->transitionTo(TikTokCatalog::QUEUED);
            $package = $queued->package;
            $package['publish'] = array_merge(TikTokCatalog::defaultPublish(), [
                'note' => TikTokCatalog::CONFIRM_NOTE,
                'confirmed_by' => $user->id,
            ]);
            $queued->package = $package;
            $queued->save();

            return $queued->fresh();
        });
    }

    /**
     * @param  array{views: int, completion_percent: float|int, follows: int}  $data
     * @return array{clip: TikTokClip, next: array{outcome: string, clip?: TikTokClip, reason?: string}|null}
     */
    public function recordResults(User $user, TikTokClip $clip, array $data): array
    {
        $this->owned($user, $clip);

        $saved = DB::transaction(function () use ($clip, $data) {
            $fresh = $this->lock($clip);
            if ($fresh->status === TikTokCatalog::LEARNED) {
                throw new TikTokActionException(
                    TikTokCatalog::ERROR_ALREADY_LEARNED,
                    'Results are already saved for this clip.',
                );
            }
            if (! in_array($fresh->status, [TikTokCatalog::WAITING, TikTokCatalog::QUEUED, TikTokCatalog::POSTED], true)) {
                throw new TikTokActionException(
                    TikTokCatalog::ERROR_INVALID_STATE,
                    'Results can be saved once a clip is waiting, confirmed, or posted.',
                );
            }

            $fresh->views = (int) $data['views'];
            $fresh->completion_percent = $data['completion_percent'];
            $fresh->follows = (int) $data['follows'];
            $kind = $fresh->package['idea_kind'] ?? TikTokCatalog::OBSERVATION;
            $fresh->learning_note = TikTokCatalog::learningNote(
                is_string($kind) ? $kind : TikTokCatalog::OBSERVATION,
                (float) $data['completion_percent'],
                (int) $data['views'],
                (int) $data['follows'],
            );
            $fresh->save();

            return $fresh->transitionTo(TikTokCatalog::LEARNED);
        });

        // Outside the transaction so a failed next clip cannot undo the results.
        try {
            $next = $this->run($user);
        } catch (Throwable $e) {
            Log::warning('TikTok engine could not prepare the next clip', [
                'user_id' => $user->id,
                'clip_id' => $saved->id,
                'error' => $e->getMessage(),
            ]);
            $next = null;
        }

        return ['clip' => $saved->fresh(), 'next' => $next];
    }

    /**
     * Discarding a queued clip frees its monthly allowance, because the allowance
     * is a count of clips that are still queued and not discarded.
     */
    public function discard(User $user, TikTokClip $clip): TikTokClip
    {
        $this->owned($user, $clip);

        return DB::transaction(function () use ($clip) {
            $fresh = $this->lock($clip);
            if ($fresh->status === TikTokCatalog::DISCARDED) {
                return $fresh;
            }
            if (! in_array($fresh->status, [TikTokCatalog::DRAFTED, TikTokCatalog::WAITING, TikTokCatalog::QUEUED], true)) {
                throw new TikTokActionException(
                    TikTokCatalog::ERROR_INVALID_STATE,
                    'This clip can no longer be skipped.',
                );
            }

            return $fresh->transitionTo(TikTokCatalog::DISCARDED);
        });
    }

    public function reschedule(User $user, TikTokClip $clip, CarbonInterface $at): TikTokClip
    {
        $this->owned($user, $clip);
        $atUtc = CarbonImmutable::parse($at)->utc();
        $earliest = CarbonImmutable::now()->utc()->addMinutes(TikTokCatalog::MIN_LEAD_MINUTES);
        $latest = CarbonImmutable::now()->utc()->addDays(TikTokCatalog::MAX_RESCHEDULE_DAYS);
        if ($atUtc->lessThan($earliest) || $atUtc->greaterThan($latest)) {
            throw new TikTokActionException(TikTokCatalog::ERROR_INVALID_TIME, TikTokCatalog::invalidTimeMessage());
        }

        return DB::transaction(function () use ($clip, $atUtc) {
            $fresh = $this->lock($clip);
            if ($fresh->status !== TikTokCatalog::WAITING) {
                throw new TikTokActionException(
                    TikTokCatalog::ERROR_INVALID_STATE,
                    'Only a clip that is waiting for your yes can change time.',
                );
            }

            $package = $fresh->package;
            $package['rescheduled_by_user'] = true;
            $fresh->suggested_for = $atUtc;
            $fresh->package = $package;
            $fresh->save();

            return $fresh->fresh();
        });
    }

    private function owned(User $user, TikTokClip $clip): void
    {
        if ((int) $clip->user_id !== (int) $user->id) {
            throw (new ModelNotFoundException)->setModel(TikTokClip::class, [$clip->id]);
        }
    }

    private function lock(TikTokClip $clip): TikTokClip
    {
        $fresh = TikTokClip::query()->whereKey($clip->id)->lockForUpdate()->first();
        if (! $fresh) {
            throw (new ModelNotFoundException)->setModel(TikTokClip::class, [$clip->id]);
        }

        return $fresh;
    }

    /**
     * The daily cap counts confirms in the last 24 hours. A discard removes that
     * confirm from the count. This is not the X rate-limit budget.
     */
    private function assertCanConfirm(User $user, TikTokClip $clip): void
    {
        $status = $this->allowance->status($user, $clip);
        if (! $status['plan_found']) {
            throw new TikTokActionException(TikTokCatalog::ERROR_NO_PLAN, TikTokCatalog::noPlanMessage());
        }
        if (! $this->allowance->canQueue($user, $clip)) {
            throw new TikTokActionException(
                TikTokCatalog::ERROR_ALLOWANCE_SPENT,
                TikTokCatalog::allowanceSpentMessage($status['limit'], substr($status['period_end'], 0, 10)),
            );
        }

        $cap = $this->settings->tiktokDailyClipCap();
        $recent = TikTokClip::query()
            ->forUser($user)
            ->whereKeyNot($clip->id)
            ->whereNull('discarded_at')
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', now()->subHours(24))
            ->count();
        if ($recent >= $cap) {
            throw new TikTokActionException(TikTokCatalog::ERROR_DAILY_CAP, TikTokCatalog::dailyCapMessage($cap));
        }
    }
}
