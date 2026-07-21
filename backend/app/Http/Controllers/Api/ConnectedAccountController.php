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

    public function connectStatus(Request $request): JsonResponse
    {
        $account = $request->user()
            ->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        if ($account) {
            return response()->json([
                'available' => true,
                'connected' => true,
                'username' => $account->username,
                'message' => "@{$account->username} is connected.",
            ]);
        }

        return response()->json([
            'available' => false,
            'connected' => false,
            'authorize_url' => null,
            'message' => 'X OAuth integration will be added in the final API stage.',
        ]);
    }
}
