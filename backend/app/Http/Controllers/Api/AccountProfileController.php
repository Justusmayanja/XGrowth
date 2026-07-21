<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Services\GrowthAgent\GrowthAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'goal' => ['required', 'string', 'max:100'],
            'niche' => ['nullable', 'string', 'max:100'],
            'custom_niche' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:50'],
            'audience' => ['nullable', 'string', 'max:2000'],
            'mode' => ['nullable', 'in:reactive,proactive'],
            'automation_level' => ['nullable', 'integer', 'min:0', 'max:3'],
            'max_silence_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
        ]);

        $profile = AccountProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                ...$validated,
                'onboarding_completed' => true,
            ]
        );

        app(GrowthAgentService::class)->evaluateOpportunities($request->user()->id);

        return response()->json(['profile' => $profile]);
    }
}
