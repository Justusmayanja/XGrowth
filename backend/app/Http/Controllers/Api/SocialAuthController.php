<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SocialAuthController extends Controller
{
    public function providers(): JsonResponse
    {
        return response()->json([
            'google' => $this->configured('google'),
            'x' => $this->configured('x'),
        ]);
    }

    public function redirect(string $provider): RedirectResponse
    {
        if (! in_array($provider, ['google', 'x'], true) || ! $this->configured($provider)) {
            return $this->fail($this->unavailableMessage($provider));
        }

        $verifier = $this->verifier();
        $state = Crypt::encryptString(json_encode([
            'provider' => $provider,
            'verifier' => $verifier,
            'exp' => now()->addMinutes(10)->timestamp,
        ]));

        $query = $provider === 'google'
            ? [
                'client_id' => config('services.google.client_id'),
                'redirect_uri' => config('services.google.redirect'),
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'prompt' => 'select_account',
            ]
            : [
                'response_type' => 'code',
                'client_id' => config('services.x.client_id'),
                'redirect_uri' => config('services.x.redirect'),
                'scope' => 'users.read tweet.read offline.access',
                'state' => $state,
                'code_challenge' => $this->challenge($verifier),
                'code_challenge_method' => 'S256',
            ];

        $url = $provider === 'google'
            ? 'https://accounts.google.com/o/oauth2/v2/auth'
            : 'https://twitter.com/i/oauth2/authorize';

        return redirect()->away($url.'?'.http_build_query($query));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (! in_array($provider, ['google', 'x'], true)) {
            return $this->fail('That sign-in method is not available.');
        }

        if ($request->filled('error')) {
            return $this->fail('The sign-in was cancelled. You can try again, or use email.');
        }

        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true);
        } catch (Throwable) {
            return $this->fail('That sign-in expired. Start again.');
        }

        if (! is_array($state) || ($state['provider'] ?? null) !== $provider || ($state['exp'] ?? 0) < now()->timestamp) {
            return $this->fail('That sign-in expired. Start again.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $this->fail('The sign-in did not come back with a code. Try again.');
        }

        try {
            $profile = $provider === 'google'
                ? $this->googleProfile($code)
                : $this->xProfile($code, (string) ($state['verifier'] ?? ''));
        } catch (Throwable) {
            return $this->fail('That account could not be confirmed. Try again, or use email.');
        }

        return $this->loginFromProfile($provider, $profile);
    }

    public function complete(Request $request): JsonResponse
    {
        if ($request->filled('username')) {
            $request->merge([
                'username' => strtolower(trim((string) $request->input('username'))),
            ]);
        }

        $validated = $request->validate([
            'pending' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
        ], [
            'email.unique' => 'That email already has an account. Sign in instead.',
            'username.unique' => 'That username is taken.',
            'username.regex' => 'Use letters, numbers, and underscores only.',
        ]);

        try {
            $pending = json_decode(Crypt::decryptString($validated['pending']), true);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'email' => ['That sign-in expired. Start again.'],
            ]);
        }

        if (! is_array($pending) || ($pending['purpose'] ?? null) !== 'finish' || ($pending['exp'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'email' => ['That sign-in expired. Start again.'],
            ]);
        }

        $provider = $pending['provider'] ?? null;
        if (! in_array($provider, ['google', 'x'], true)) {
            throw ValidationException::withMessages([
                'email' => ['That sign-in method is not available.'],
            ]);
        }

        $username = isset($validated['username'])
            ? strtolower($validated['username'])
            : $this->freeUsername($pending['username'] ?? null);

        $user = $this->createFromProvider(
            $provider,
            (string) $pending['id'],
            $validated['email'],
            (string) ($pending['name'] ?? 'Mackie17 member'),
            $username,
        );

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('api')->plainTextToken,
        ], 201);
    }

    /**
     * @param  array{id: string, email: ?string, name: string, username: ?string}  $profile
     */
    private function loginFromProfile(string $provider, array $profile): RedirectResponse
    {
        $column = $provider === 'google' ? 'google_id' : 'x_id';
        $user = User::query()->where($column, $profile['id'])->first();

        if (! $user && $profile['email']) {
            $user = User::query()->where('email', $profile['email'])->first();
            if ($user) {
                if ($user->{$column} && $user->{$column} !== $profile['id']) {
                    return $this->fail('That email already belongs to another account. Sign in with email.');
                }
                $user->{$column} = $profile['id'];
                if (! $user->username) {
                    $user->username = $this->freeUsername($profile['username']);
                }
                $user->save();
            }
        }

        if (! $user) {
            if (! $profile['email']) {
                $pending = Crypt::encryptString(json_encode([
                    'purpose' => 'finish',
                    'provider' => $provider,
                    'id' => $profile['id'],
                    'name' => $profile['name'],
                    'username' => $profile['username'],
                    'exp' => now()->addMinutes(15)->timestamp,
                ]));

                return redirect()->away($this->frontend('/register?finish='.$provider.'#pending='.urlencode($pending)));
            }

            $user = $this->createFromProvider(
                $provider,
                $profile['id'],
                $profile['email'],
                $profile['name'],
                $this->freeUsername($profile['username']),
            );
        }

        if ($user->is_blocked) {
            return $this->fail('This account has been suspended. Contact support for help.');
        }

        $token = $user->createToken('api')->plainTextToken;

        return redirect()->away($this->frontend('/auth/callback#token='.urlencode($token)));
    }

    private function createFromProvider(string $provider, string $providerId, string $email, string $name, ?string $username): User
    {
        return User::create([
            'name' => $name !== '' ? $name : 'Mackie17 member',
            'username' => $username,
            'email' => $email,
            'google_id' => $provider === 'google' ? $providerId : null,
            'x_id' => $provider === 'x' ? $providerId : null,
            'password' => Str::password(40),
            'email_verified_at' => now(),
            'subscription_plan' => 'creator',
        ]);
    }

    /**
     * @return array{id: string, email: ?string, name: string, username: ?string}
     */
    private function googleProfile(string $code): array
    {
        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $info = Http::withToken($token['access_token'])
            ->get('https://www.googleapis.com/oauth2/v2/userinfo')
            ->throw()
            ->json();

        return [
            'id' => (string) ($info['id'] ?? ''),
            'email' => isset($info['email']) ? (string) $info['email'] : null,
            'name' => (string) ($info['name'] ?? ''),
            'username' => null,
        ];
    }

    /**
     * @return array{id: string, email: ?string, name: string, username: ?string}
     */
    private function xProfile(string $code, string $verifier): array
    {
        $token = Http::asForm()
            ->withBasicAuth((string) config('services.x.client_id'), (string) config('services.x.client_secret'))
            ->post('https://api.twitter.com/2/oauth2/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.x.redirect'),
                'code_verifier' => $verifier,
            ])->throw()->json();

        $info = Http::withToken($token['access_token'])
            ->get('https://api.twitter.com/2/users/me', [
                'user.fields' => 'name,username',
            ])->throw()->json('data');

        return [
            'id' => (string) ($info['id'] ?? ''),
            'email' => null,
            'name' => (string) ($info['name'] ?? ''),
            'username' => isset($info['username']) ? strtolower((string) $info['username']) : null,
        ];
    }

    private function freeUsername(?string $username): ?string
    {
        if (! is_string($username) || ! preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            return null;
        }

        $username = strtolower($username);

        return User::query()->where('username', $username)->exists() ? null : $username;
    }

    private function configured(string $provider): bool
    {
        return filled(config("services.$provider.client_id")) && filled(config("services.$provider.client_secret"));
    }

    private function unavailableMessage(string $provider): string
    {
        $name = $provider === 'google' ? 'Google' : 'X';

        return "$name sign-in is not turned on for this server yet. Use email, or write your username and details.";
    }

    private function verifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->away($this->frontend('/auth/callback?error='.urlencode($message)));
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('services.frontend.url'), '/').$path;
    }
}
