<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if ($request->filled('username')) {
            $request->merge([
                'username' => strtolower(trim((string) $request->input('username'))),
            ]);
        }

        $validated = $request->validate([
            'setup' => ['nullable', 'in:email,manual'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'username' => ['exclude_unless:setup,manual', 'required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'That email already has an account. Sign in instead.',
            'username.required' => 'Choose a username.',
            'username.unique' => 'That username is taken.',
            'username.regex' => 'Use letters, numbers, and underscores only.',
            'password.min' => 'Use at least 8 characters.',
            'password.confirmed' => 'Those passwords do not match.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => isset($validated['username']) ? strtolower($validated['username']) : null,
            'email' => $validated['email'],
            'password' => $validated['password'],
            'subscription_plan' => 'creator',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required_without:login', 'nullable', 'string'],
            'login' => ['required_without:email', 'nullable', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) ($validated['login'] ?? $validated['email'] ?? ''));
        $user = str_contains($login, '@')
            ? User::query()->where('email', $login)->first()
            : User::query()->where('username', strtolower($login))->first();

        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['That email or username and password do not match.'],
            ]);
        }

        if ($user->is_blocked) {
            throw ValidationException::withMessages([
                'email' => ['This account has been suspended. Contact support for help.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
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
