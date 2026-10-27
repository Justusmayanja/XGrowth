<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Models\PlatformSetup;
use App\Services\GrowthAgent\GrowthAgentService;
use App\Services\TikTok\TikTokEngineService;
use App\Support\SetupCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->accountProfile;

        return response()->json(['profile' => $profile]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'goal' => ['sometimes', 'nullable', 'string', 'max:200'],
            'purpose' => ['sometimes', 'nullable', 'in:'.implode(',', array_keys(SetupCatalog::PURPOSES))],
            'niche' => ['nullable', 'string', 'max:200'],
            'custom_niche' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:100'],
            'audience' => ['nullable', 'string', 'max:2000'],
            'mode' => ['nullable', 'in:reactive,proactive'],
            'automation_level' => ['nullable', 'integer', 'min:0', 'max:3'],
            'max_silence_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'complete_onboarding' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated['goal'])) {
            $validated['goal'] = $this->normalizeMultiValue(
                $validated['goal'],
                array_keys(SetupCatalog::GOALS['x']),
                'goal',
            );
        } else {
            unset($validated['goal']);
        }
        $validated['niche'] = isset($validated['niche'])
            ? $this->normalizeMultiValue(
                $validated['niche'],
                ['football', 'technology', 'business', 'crypto', 'motivation', 'memes', 'local_news', 'entertainment'],
                'niche',
            )
            : null;
        $validated['tone'] = isset($validated['tone'])
            ? $this->normalizeMultiValue(
                $validated['tone'],
                ['professional', 'passionate', 'funny', 'motivational', 'news'],
                'tone',
            )
            : null;

        $completeOnboarding = $request->boolean('complete_onboarding');
        unset($validated['complete_onboarding']);

        if ($request->exists('complete_onboarding')) {
            $validated['onboarding_completed'] = $completeOnboarding;
        }

        $profile = AccountProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        if (isset($validated['goal'])) {
            PlatformSetup::query()
                ->where('user_id', $request->user()->id)
                ->where('platform', 'x')
                ->update(['goals' => $validated['goal']]);
        }

        if ($completeOnboarding) {
            app(GrowthAgentService::class)->evaluateOpportunities($request->user()->id);
        }

        return response()->json(['profile' => $profile]);
    }

    public function timezone(Request $request, TikTokEngineService $engine): JsonResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
        ]);

        $profile = $request->user()->accountProfile;
        if (! $profile) {
            throw ValidationException::withMessages([
                'timezone' => ['Finish setting up your account first.'],
            ]);
        }

        $profile->update(['timezone' => $validated['timezone']]);
        $engine->refreshAssumedTime($request->user()->fresh());

        return response()->json([
            'data' => [
                'timezone' => $validated['timezone'],
                'assumed' => false,
            ],
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeMultiValue(string $value, array $allowed, string $field, int $max = 3): string
    {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));

        if ($items === []) {
            throw ValidationException::withMessages([
                $field => ['Select at least one option.'],
            ]);
        }

        if (count($items) > $max) {
            throw ValidationException::withMessages([
                $field => ["You can select at most {$max} options."],
            ]);
        }

        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                throw ValidationException::withMessages([
                    $field => ["Invalid selection: {$item}."],
                ]);
            }
        }

        return implode(',', $items);
    }
}
