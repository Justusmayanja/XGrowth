<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectedAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->connectedAccounts()
            ->select(['id', 'platform', 'username', 'is_active', 'created_at'])
            ->get();

        return response()->json(['accounts' => $accounts]);
    }

    public function connectStatus(): JsonResponse
    {
        return response()->json([
            'available' => false,
            'message' => 'X OAuth integration will be added in the final API stage.',
        ]);
    }
}
