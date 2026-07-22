<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AICore\AiUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageController extends Controller
{
    public function __construct(
        private readonly AiUsageService $usage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->usage->status($request->user()));
    }
}
