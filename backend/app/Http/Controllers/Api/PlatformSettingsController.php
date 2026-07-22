<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformSettingsController extends Controller
{
    public function __construct(
        private readonly PlatformSettingsService $settings,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'groups' => $this->settings->groupedForAdmin(),
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', Rule::in(array_keys(PlatformSettingsService::DEFINITIONS))],
            'settings.*.value' => ['required'],
        ]);

        $updatedKeys = $this->settings->bulkUpdate(
            $validated['settings'],
            $request->user()->id
        );

        if ($updatedKeys === []) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        return response()->json([
            'groups' => $this->settings->groupedForAdmin(),
            'updated' => $updatedKeys,
        ]);
    }
}
