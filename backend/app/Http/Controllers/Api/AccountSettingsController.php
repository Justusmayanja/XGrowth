<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('accountProfile');
        $profile = $user->accountProfile;

        return response()->json([
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'subscription_plan' => $user->subscription_plan,
            ],
            'automation' => [
                'engine_paused' => (bool) ($profile?->engine_paused ?? false),
                'mode' => $profile?->mode ?? 'reactive',
                'automation_level' => $profile?->automation_level ?? 1,
                'max_silence_minutes' => $profile?->max_silence_minutes ?? 60,
                'auto_post_enabled' => (bool) ($profile?->auto_post_enabled ?? true),
                'auto_reply_enabled' => (bool) ($profile?->auto_reply_enabled ?? false),
                'auto_engage_enabled' => (bool) ($profile?->auto_engage_enabled ?? true),
            ],
            'notifications' => [
                'notify_post_results' => (bool) ($profile?->notify_post_results ?? true),
                'notify_draft_ready' => (bool) ($profile?->notify_draft_ready ?? true),
                'notify_weekly_recap' => (bool) ($profile?->notify_weekly_recap ?? false),
                'notify_safety_alerts' => (bool) ($profile?->notify_safety_alerts ?? true),
            ],
        ]);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }

        $user->save();

        return response()->json([
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'subscription_plan' => $user->subscription_plan,
            ],
            'message' => 'Account updated.',
        ]);
    }

    public function updateAutomation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'engine_paused' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'in:reactive,proactive'],
            'automation_level' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'max_silence_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
            'auto_post_enabled' => ['sometimes', 'boolean'],
            'auto_reply_enabled' => ['sometimes', 'boolean'],
            'auto_engage_enabled' => ['sometimes', 'boolean'],
        ]);

        $profile = $request->user()->accountProfile;

        if (! $profile) {
            return response()->json(['message' => 'Complete onboarding first.'], 422);
        }

        $profile->update($validated);
        $profile->refresh();
        $profile->rememberXSettings();

        $fullAuto = (int) $profile->automation_level === 3
            && $profile->auto_post_enabled
            && ! $profile->engine_paused;

        return response()->json([
            'automation' => $this->automationPayload($profile),
            'message' => $fullAuto
                ? 'Full automation is on. Mackie17 will draft and post on X even when you are offline.'
                : 'Automation settings saved.',
        ]);
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notify_post_results' => ['sometimes', 'boolean'],
            'notify_draft_ready' => ['sometimes', 'boolean'],
            'notify_weekly_recap' => ['sometimes', 'boolean'],
            'notify_safety_alerts' => ['sometimes', 'boolean'],
        ]);

        $profile = $request->user()->accountProfile;

        if (! $profile) {
            return response()->json(['message' => 'Complete onboarding first.'], 422);
        }

        $profile->update($validated);

        return response()->json([
            'notifications' => $this->notificationsPayload($profile->fresh()),
            'message' => 'Notification preferences saved.',
        ]);
    }

    public function revokeSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        return response()->json(['message' => 'Signed out of all other devices.']);
    }

    private function automationPayload($profile): array
    {
        return [
            'engine_paused' => (bool) ($profile?->engine_paused ?? false),
            'mode' => $profile?->mode ?? 'reactive',
            'automation_level' => $profile?->automation_level ?? 1,
            'max_silence_minutes' => $profile?->max_silence_minutes ?? 60,
            'auto_post_enabled' => (bool) ($profile?->auto_post_enabled ?? true),
            'auto_reply_enabled' => (bool) ($profile?->auto_reply_enabled ?? false),
            'auto_engage_enabled' => (bool) ($profile?->auto_engage_enabled ?? true),
        ];
    }

    private function notificationsPayload($profile): array
    {
        return [
            'notify_post_results' => (bool) ($profile?->notify_post_results ?? true),
            'notify_draft_ready' => (bool) ($profile?->notify_draft_ready ?? true),
            'notify_weekly_recap' => (bool) ($profile?->notify_weekly_recap ?? false),
            'notify_safety_alerts' => (bool) ($profile?->notify_safety_alerts ?? true),
        ];
    }
}
