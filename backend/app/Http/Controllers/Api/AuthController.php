<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\EmailCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private EmailCodeService $codes) {}

    public function sendCode(Request $request): JsonResponse
    {
        if ($request->filled('username')) {
            $request->merge([
                'username' => strtolower(trim((string) $request->input('username'))),
            ]);
        }

        if ($request->filled('email')) {
            $request->merge([
                'email' => strtolower(trim((string) $request->input('email'))),
            ]);
        }

        $validated = $request->validate([
            'purpose' => ['required', 'in:login,register'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['exclude_unless:purpose,register', 'required', 'string', 'max:255'],
            'username' => ['exclude_unless:purpose,register', 'required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
        ], [
            'email.email' => 'Enter a valid email.',
            'name.required' => 'Enter your name.',
            'username.required' => 'Choose a username.',
            'username.unique' => 'That username is taken.',
            'username.regex' => 'Use letters, numbers, and underscores only.',
        ]);

        $result = $this->codes->send(
            $validated['purpose'],
            $validated['email'],
            $validated['name'] ?? null,
            $validated['username'] ?? null,
        );

        return response()->json($result);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        if ($request->filled('email')) {
            $request->merge([
                'email' => strtolower(trim((string) $request->input('email'))),
            ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
        ], [
            'code.digits' => 'Enter the 6-digit code from your email.',
        ]);

        $result = $this->codes->verify($validated['email'], $validated['code']);
        $status = $result['created'] ? 201 : 200;
        unset($result['created']);

        return response()->json($result, $status);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load([
            'accountProfile',
            'connectedAccounts:id,user_id,platform,username,is_active',
        ]);

        return response()->json(['user' => $user]);
    }
}
