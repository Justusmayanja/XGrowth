<?php

namespace App\Services\TikTok;

use App\Models\PlatformSetup;
use App\Models\TikTokClip;
use App\Models\User;
use App\Support\TikTokCatalog;
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
     * Phase 3 replaces this with the monthly clips allowance.
     * It stays true until that check exists, so this phase never spends or blocks on billing.
     */
    protected function withinAllowance(User $user): bool
    {
        return true;
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

        if (! $this->withinAllowance($user)) {
            throw new \LogicException('TikTok monthly allowance is enforced in Phase 3.');
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
}
