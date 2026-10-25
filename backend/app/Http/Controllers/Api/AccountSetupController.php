<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Models\BillingSubscription;
use App\Models\PlatformSetup;
use App\Models\User;
use App\Services\AICore\AICoreService;
use App\Services\Billing\TrialService;
use App\Services\GrowthAgent\GrowthAgentService;
use App\Services\YouTube\YouTubeEngineService;
use App\Support\SetupCatalog;
use App\Support\YouTubeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccountSetupController extends Controller
{
    private const WEEKLY_TIME = [
        'under_3' => 'under 3 hours',
        '3_to_7' => '3 to 7 hours',
        'over_7' => 'more than 7 hours',
    ];

    private const PRESENCE = [
        'on_camera' => 'happy to be on camera',
        'voice_only' => 'voice only, no face',
        'faceless' => 'faceless: text, screen recordings, or stock footage',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($request->user()));
    }

    public function platforms(Request $request, TrialService $trials): JsonResponse
    {
        $validated = $request->validate([
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', Rule::in(SetupCatalog::PLATFORMS)],
            'tiers' => ['nullable', 'array'],
            'tiers.*' => ['string', 'in:starter,pro,agency'],
        ]);

        $chosen = array_values(array_intersect(SetupCatalog::PLATFORMS, $validated['platforms']));

        AccountProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['platforms' => implode(',', $chosen)],
        );

        $trials->syncForPlatforms($request->user(), $chosen, $validated['tiers'] ?? []);

        return response()->json($this->state($request->user()->fresh()));
    }

    public function about(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(array_keys(SetupCatalog::PURPOSES))],
            'niche' => ['nullable', 'array', 'max:3'],
            'niche.*' => ['string', Rule::in(SetupCatalog::NICHES)],
            'custom_niche' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:255'],
            'tone' => ['required', 'array', 'min:1', 'max:3'],
            'tone.*' => ['string', Rule::in(SetupCatalog::TONES)],
            'discovery' => ['nullable', 'array'],
            'discovery.interests' => ['nullable', 'string', 'max:500'],
            'discovery.weekly_time' => ['nullable', Rule::in(array_keys(self::WEEKLY_TIME))],
            'discovery.presence' => ['nullable', Rule::in(array_keys(self::PRESENCE))],
            'discovery.picked' => ['nullable', 'string', 'max:255'],
        ]);

        $niches = array_values(array_unique($validated['niche'] ?? []));
        $customNiche = trim((string) ($validated['custom_niche'] ?? ''));

        if ($niches === [] && $customNiche === '') {
            throw ValidationException::withMessages([
                'niche' => ['Pick a niche or write your own.'],
            ]);
        }

        AccountProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'purpose' => $validated['purpose'],
                'niche' => $niches === [] ? null : implode(',', $niches),
                'custom_niche' => $customNiche === '' ? null : $customNiche,
                'description' => trim($validated['description']),
                'audience' => isset($validated['audience']) ? trim($validated['audience']) : null,
                'tone' => implode(',', array_values(array_unique($validated['tone']))),
                'discovery' => $validated['discovery'] ?? null,
            ],
        );

        return response()->json($this->state($request->user()));
    }

    public function platform(Request $request, string $platform): JsonResponse
    {
        if (! in_array($platform, SetupCatalog::PLATFORMS, true)) {
            abort(404);
        }

        $user = $request->user();
        $profile = $user->accountProfile()->first();

        if (! $profile || ! $this->aboutComplete($profile)) {
            throw ValidationException::withMessages([
                'about' => ['Finish the questions about you first.'],
            ]);
        }

        $rules = [
            'goals' => ['required', 'array', 'min:1', 'max:3'],
            'goals.*' => ['string', Rule::in(array_keys(SetupCatalog::GOALS[$platform]))],
        ];

        if ($platform === 'x') {
            $rules['automation_level'] = ['required', 'integer', 'min:0', 'max:3'];
            $rules['mode'] = ['required', 'in:reactive,proactive'];
        }

        if ($platform === 'youtube') {
            $rules['publish'] = ['required', 'in:auto,wait'];
            $rules['video_mode'] = ['required', Rule::in(YouTubeCatalog::MODES)];
            $rules['format'] = ['required_unless:video_mode,footage', 'nullable', Rule::in(YouTubeCatalog::FORMATS)];
        }

        $validated = $request->validate($rules);
        $goals = implode(',', array_values(array_unique($validated['goals'])));

        $settings = match ($platform) {
            'x' => ['automation_level' => (int) $validated['automation_level'], 'mode' => $validated['mode']],
            'youtube' => array_filter([
                'publish' => $validated['publish'],
                'video_mode' => $validated['video_mode'],
                'format' => $validated['video_mode'] === 'footage' ? null : ($validated['format'] ?? null),
            ], fn ($value) => $value !== null),
            default => ['publish' => 'confirm'],
        };

        PlatformSetup::updateOrCreate(
            ['user_id' => $user->id, 'platform' => $platform],
            ['goals' => $goals, 'settings' => $settings, 'completed_at' => now()],
        );

        $chosen = $profile->platformList();
        if (! in_array($platform, $chosen, true)) {
            $chosen[] = $platform;
        }

        $updates = ['platforms' => implode(',', array_values(array_intersect(SetupCatalog::PLATFORMS, $chosen)))];

        if ($platform === 'x') {
            $updates['goal'] = $goals;
            $updates['automation_level'] = $settings['automation_level'];
            $updates['mode'] = $settings['mode'];
            $updates['auto_post_enabled'] = (int) $settings['automation_level'] >= 2;
        }

        $firstCompletion = ! $profile->onboarding_completed;
        $updates['onboarding_completed'] = true;

        $profile->update($updates);

        if ($platform === 'x') {
            app(\App\Services\GrowthAgent\GrowthBrainService::class)->forgetScheduledCooldown($user->id);
        }

        if ($platform === 'youtube') {
            app(YouTubeEngineService::class)->run($user->fresh());
        }

        if ($firstCompletion) {
            app(GrowthAgentService::class)->evaluateOpportunities($user->id);
        }

        return response()->json($this->state($user->fresh()));
    }

    public function nicheIdeas(Request $request, AICoreService $ai): JsonResponse
    {
        $validated = $request->validate([
            'interests' => ['required', 'string', 'min:3', 'max:500'],
            'weekly_time' => ['required', Rule::in(array_keys(self::WEEKLY_TIME))],
            'presence' => ['required', Rule::in(array_keys(self::PRESENCE))],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', Rule::in(SetupCatalog::PLATFORMS)],
        ]);

        $platforms = $validated['platforms'] ?? $request->user()->accountProfile()->first()?->platformList() ?? [];
        if ($platforms === []) {
            $platforms = ['x'];
        }

        if ($ai->isConfigured()) {
            try {
                $ideas = $ai->suggestNiches(
                    $request->user(),
                    trim($validated['interests']),
                    self::WEEKLY_TIME[$validated['weekly_time']],
                    self::PRESENCE[$validated['presence']],
                    array_map(fn (string $p) => SetupCatalog::PLATFORM_NAMES[$p], $platforms),
                );

                return response()->json(['ideas' => $ideas, 'source' => 'ai']);
            } catch (RuntimeException) {
                // Fall through to the rule-based ideas so the step never blocks.
            }
        }

        return response()->json([
            'ideas' => $this->basicNicheIdeas(
                trim($validated['interests']),
                $validated['weekly_time'],
                $validated['presence'],
                $platforms,
            ),
            'source' => 'basic',
        ]);
    }

    /**
     * @param  list<string>  $platforms
     * @return list<array{niche: string, angle: string}>
     */
    private function basicNicheIdeas(string $interests, string $weeklyTime, string $presence, array $platforms): array
    {
        $topics = array_values(array_filter(array_map(
            fn (string $t) => trim($t, " .\t\n"),
            preg_split('/,|;|\band\b|\//i', $interests) ?: [],
        ), fn (string $t) => mb_strlen($t) >= 2));

        if ($topics === []) {
            $topics = [$interests];
        }

        $lead = $platforms[0];
        $format = match ($lead) {
            'tiktok' => 'short clips that each answer one question',
            'youtube' => $weeklyTime === 'under_3'
                ? 'one focused video a week'
                : 'weekly videos that teach one idea from start to finish',
            default => 'daily posts with one clear opinion or tip',
        };
        $style = match ($presence) {
            'faceless' => 'faceless',
            'voice_only' => 'voice-over',
            default => 'on-camera',
        };

        $templates = [
            fn (string $t) => [
                'niche' => ucfirst($t).' for beginners',
                'angle' => "For people just starting with {$t}: {$format}, {$style}.",
            ],
            fn (string $t) => [
                'niche' => ucfirst($t).' on a budget',
                'angle' => "For people who want results in {$t} without spending much: cheap picks, swaps, and honest reviews.",
            ],
            fn (string $t) => [
                'niche' => 'Learning '.$t.' in public',
                'angle' => "Document your own progress in {$t} each week. People follow the story and the lessons.",
            ],
        ];

        $ideas = [];
        foreach ($templates as $i => $template) {
            $topic = $topics[$i % count($topics)];
            $ideas[] = $template(mb_strtolower(mb_substr($topic, 0, 40)));
        }

        return $ideas;
    }

    private function aboutComplete(AccountProfile $profile): bool
    {
        return $profile->purpose !== null
            && mb_strlen(trim((string) $profile->description)) >= 20
            && ($profile->niche || $profile->custom_niche)
            && $profile->tones() !== [];
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        $profile = $user->accountProfile()->first();
        $setups = PlatformSetup::query()->where('user_id', $user->id)->get()->keyBy('platform');

        $setupState = [];
        foreach (SetupCatalog::PLATFORMS as $platform) {
            $row = $setups->get($platform);
            $setupState[$platform] = [
                'goals' => $row?->goalList() ?? [],
                'settings' => $row?->settings ?? (object) [],
                'completed' => $row?->completed_at !== null,
            ];
        }

        return [
            'platforms' => $profile?->platformList() ?? [],
            'paid_platforms' => $this->paidPlatforms($user),
            'about' => $profile ? [
                'purpose' => $profile->purpose,
                'niche' => $profile->niches(),
                'custom_niche' => $profile->custom_niche,
                'description' => $profile->description,
                'audience' => $profile->audience,
                'tone' => $profile->tones(),
                'discovery' => $profile->discovery,
            ] : null,
            'about_complete' => $profile ? $this->aboutComplete($profile) : false,
            'setups' => $setupState,
            'onboarding_completed' => (bool) $profile?->onboarding_completed,
        ];
    }

    /** @return list<string> */
    private function paidPlatforms(User $user): array
    {
        $subscription = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'past_due', 'trialing', 'pending'])
            ->orderByRaw("case status when 'active' then 0 when 'past_due' then 0 when 'trialing' then 1 else 2 end")
            ->latest('id')
            ->with('lines')
            ->first();

        if (! $subscription) {
            return [];
        }

        return array_values(array_intersect(
            SetupCatalog::PLATFORMS,
            $subscription->lines->pluck('platform')->all(),
        ));
    }
}
