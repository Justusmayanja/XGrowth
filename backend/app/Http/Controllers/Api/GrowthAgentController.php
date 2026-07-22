<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiDecision;
use App\Services\GrowthAgent\GrowthAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrowthAgentController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        $profile = $request->user()->accountProfile;

        return response()->json([
            'settings' => [
                'mode' => $profile?->mode ?? 'reactive',
                'automation_level' => $profile?->automation_level ?? 1,
                'max_silence_minutes' => $profile?->max_silence_minutes ?? 60,
                'engine_paused' => (bool) ($profile?->engine_paused ?? false),
                'auto_post_enabled' => (bool) ($profile?->auto_post_enabled ?? true),
                'auto_reply_enabled' => (bool) ($profile?->auto_reply_enabled ?? false),
                'auto_engage_enabled' => (bool) ($profile?->auto_engage_enabled ?? true),
                'onboarding_completed' => (bool) ($profile?->onboarding_completed),
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', 'in:reactive,proactive'],
            'automation_level' => ['nullable', 'integer', 'min:0', 'max:3'],
            'max_silence_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'engine_paused' => ['nullable', 'boolean'],
            'auto_post_enabled' => ['nullable', 'boolean'],
            'auto_reply_enabled' => ['nullable', 'boolean'],
            'auto_engage_enabled' => ['nullable', 'boolean'],
        ]);

        $profile = $request->user()->accountProfile;

        if (! $profile) {
            return response()->json(['message' => 'Complete onboarding first.'], 422);
        }

        $profile->update($validated);

        return response()->json(['settings' => $profile->only([
            'mode', 'automation_level', 'max_silence_minutes', 'onboarding_completed',
            'engine_paused', 'auto_post_enabled', 'auto_reply_enabled', 'auto_engage_enabled',
        ])]);
    }

    public function evaluate(Request $request, GrowthAgentService $growthAgent): JsonResponse
    {
        $profile = $request->user()->accountProfile;

        if (! $profile?->onboarding_completed) {
            return response()->json(['message' => 'Complete onboarding first.'], 422);
        }

        $decision = $growthAgent->evaluateOpportunities($request->user()->id);

        $latest = AiDecision::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        return response()->json([
            'decision' => $decision,
            'latest' => $latest,
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $decisions = AiDecision::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(20)
            ->get();

        if ($decisions->isEmpty()) {
            return response()->json([
                'decisions' => [],
                'empty_message' => 'Run an evaluation to see how Xpulse scores opportunities in your niche.',
            ]);
        }

        return response()->json(['decisions' => $decisions]);
    }
}
