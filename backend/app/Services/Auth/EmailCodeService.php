<?php

namespace App\Services\Auth;

use App\Mail\LoginCodeMail;
use App\Models\EmailLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailCodeService
{
    /**
     * @return array{message: string, expires_in: int}
     */
    public function send(string $purpose, string $email, ?string $name = null, ?string $username = null): array
    {
        $email = strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($purpose === 'login') {
            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => ['No account uses that email. Create an account.'],
                ]);
            }

            if ($user->is_blocked) {
                throw ValidationException::withMessages([
                    'email' => ['This account has been suspended. Contact support for help.'],
                ]);
            }
        }

        if ($purpose === 'register') {
            if ($user) {
                throw ValidationException::withMessages([
                    'email' => ['That email already has an account. Sign in instead.'],
                ]);
            }

            $username = strtolower(trim((string) $username));
            $name = trim((string) $name);

            if (User::query()->where('username', $username)->exists()) {
                throw ValidationException::withMessages([
                    'username' => ['That username is taken.'],
                ]);
            }
        }

        EmailLoginCode::query()
            ->where('created_at', '<', now()->subDay())
            ->delete();

        $sentRecently = EmailLoginCode::query()
            ->where('email', $email)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($sentRecently) {
            throw ValidationException::withMessages([
                'email' => ['A code was just sent. Wait a minute before asking for another.'],
            ]);
        }

        $sentThisHour = EmailLoginCode::query()
            ->where('email', $email)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($sentThisHour >= 5) {
            throw ValidationException::withMessages([
                'email' => ['Too many codes were sent to that email. Try again in an hour.'],
            ]);
        }

        $code = (string) random_int(100000, 999999);
        $minutes = max(1, (int) config('auth.email_code_ttl', 10));

        EmailLoginCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $record = EmailLoginCode::query()->create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'name' => $purpose === 'register' ? $name : null,
            'username' => $purpose === 'register' ? $username : null,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        try {
            Mail::to($email)->send(new LoginCodeMail($code, $minutes));
        } catch (\Throwable $e) {
            $record->delete();
            report($e);

            throw ValidationException::withMessages([
                'email' => ['We could not send the email. Try again in a minute.'],
            ]);
        }

        return [
            'message' => "We sent a code to {$email}.",
            'expires_in' => $minutes * 60,
        ];
    }

    /**
     * @return array{user: User, token: string, created: bool}
     */
    public function verify(string $email, string $code): array
    {
        $email = strtolower(trim($email));
        $record = EmailLoginCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $record || $record->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => ['That code expired. Send a new one.'],
            ]);
        }

        if ($record->attempts >= 5) {
            throw ValidationException::withMessages([
                'code' => ['Too many tries. Send a new code.'],
            ]);
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['That code does not match.'],
            ]);
        }

        $created = false;

        if ($record->purpose === 'register') {
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['That email already has an account. Sign in instead.'],
                ]);
            }

            $username = strtolower((string) $record->username);

            if (User::query()->where('username', $username)->exists()) {
                throw ValidationException::withMessages([
                    'username' => ['That username is taken.'],
                ]);
            }

            $user = User::query()->create([
                'name' => (string) $record->name,
                'username' => $username,
                'email' => $email,
                'password' => Str::password(40),
                'email_verified_at' => now(),
                'subscription_plan' => 'creator',
            ]);
            $created = true;
        } else {
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => ['No account uses that email. Create an account.'],
                ]);
            }

            if ($user->is_blocked) {
                throw ValidationException::withMessages([
                    'email' => ['This account has been suspended. Contact support for help.'],
                ]);
            }

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return [
            'user' => $user,
            'token' => $user->createToken('api')->plainTextToken,
            'created' => $created,
        ];
    }
}
