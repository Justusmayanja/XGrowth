<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GrowthAgent\SafetyEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SafetyController extends Controller
{
    public function __construct(private readonly SafetyEngineService $safetyEngine) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->safetyEngine->status($request->user()));
    }
}
