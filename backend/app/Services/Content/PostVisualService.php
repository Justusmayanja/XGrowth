<?php

namespace App\Services\Content;

use App\Models\AccountProfile;
use App\Models\User;
use App\Services\ApiSpend\ApiSpendService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A picture for an X post, once the last few posts were only words.
 * Illustrations come from the image model and carry no lettering.
 * A flyer is the backup: color, shape, and one short line.
 */
class PostVisualService
{
    public function __construct(private readonly ApiSpendService $budget) {}

    public function due(User $user, ?int $accountId): bool
    {
        $base = $user->posts()->where('content_type', 'tweet');
        if ($accountId) {
            $base->where(function ($query) use ($accountId) {
                $query->where('connected_account_id', $accountId)
                    ->orWhereNull('connected_account_id');
            });
        }

        $recent = (clone $base)->latest('id')->limit(4)->get(['media_path']);
        if ($recent->count() < 4) {
            return false;
        }

        if ($recent->contains(fn ($post) => filled($post->media_path))) {
            return false;
        }

        $today = (clone $base)
            ->whereNotNull('media_path')
            ->where('created_at', '>=', now()->startOfDay());

        return ! $today->exists();
    }

    /**
     * @return array{path: string, kind: string}|null
     */
    public function make(User $user, ?AccountProfile $profile, string $caption): ?array
    {
        $about = $profile ?? new AccountProfile;
        $kind = $this->nextKind($user);
        $bytes = null;

        if ($kind === 'image') {
            $bytes = $this->illustration($user, $about, $caption);
        }

        if ($bytes === null) {
            $bytes = $this->flyerPng($about, $caption);
            $kind = 'flyer';
        }

        if ($bytes === null) {
            return null;
        }

        $extension = str_starts_with($bytes, "\xFF\xD8") ? 'jpg' : 'png';
        $path = 'post-visuals/'.$user->id.'/'.str_replace('-', '', (string) Str::uuid()).'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        return ['path' => $path, 'kind' => $kind];
    }

    private function nextKind(User $user): string
    {
        $count = $user->posts()->whereNotNull('media_path')->count();

        return $count % 2 === 0 ? 'image' : 'flyer';
    }

    private function illustration(User $user, AccountProfile $profile, string $caption): ?string
    {
        if (app()->runningUnitTests()) {
            return null;
        }

        $key = config('openai.api_key');
        if (! is_string($key) || $key === '') {
            return null;
        }

        if (! $this->budget->canSpend($user->id, ApiSpendService::IMAGE_MILLS)) {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(25)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => 'dall-e-3',
                    'prompt' => $this->imagePrompt($profile, $caption),
                    'size' => '1024x1024',
                    'quality' => 'standard',
                    'n' => 1,
                    'response_format' => 'b64_json',
                ]);
        } catch (Throwable $e) {
            Log::warning('Post image was not generated', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Post image was not generated', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $encoded = $response->json('data.0.b64_json');
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $bytes = base64_decode($encoded, true);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        $this->budget->spend($user->id, ApiSpendService::IMAGE_MILLS);

        return $bytes;
    }

    private function imagePrompt(AccountProfile $profile, string $caption): string
    {
        $about = trim((string) $profile->description);
        if ($about === '') {
            $about = trim((string) $profile->resolvedNiche());
        }
        $about = mb_substr((string) preg_replace('/\s+/', ' ', $about), 0, 400);
        $audience = mb_substr(trim((string) $profile->audience), 0, 160);
        $moment = mb_substr((string) preg_replace('/\s+/', ' ', $caption), 0, 180);

        $prompt = 'A single original illustration for a social account. No words, no letters, no numbers, no logos, no watermarks, no crests, and no photograph of a real person. ';
        $prompt .= "Show the world of this account: {$about}. ";
        if ($audience !== '') {
            $prompt .= "The people it is for: {$audience}. ";
        }
        $prompt .= "The moment, shown without writing it: {$moment}. ";
        $prompt .= 'Bold, simple, high contrast, like a poster illustration. Not a screenshot, not a user interface, and not a collage of text.';

        return $prompt;
    }

    public function headline(string $caption): string
    {
        $clean = trim((string) preg_replace('/https?:\/\/\S+|[#@]\S+/', '', $caption));
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
        $first = preg_split('/[.!?]/u', $clean) ?: [];
        $clause = trim((string) ($first[0] ?? $clean));
        $words = preg_split('/\s+/', $clause) ?: [];
        $line = implode(' ', array_slice($words, 0, 7));
        if (mb_strlen($line) > 48) {
            $line = rtrim(mb_substr($line, 0, 48));
        }

        return $line !== '' ? $line : 'Today';
    }

    private function footer(AccountProfile $profile): string
    {
        $source = trim((string) $profile->description);
        if ($source === '') {
            $source = trim((string) $profile->resolvedNiche());
        }
        $source = trim((string) preg_replace('/\s+/', ' ', $source));
        if ($source === '') {
            return '';
        }

        $words = preg_split('/\s+/', $source) ?: [];

        return mb_substr(implode(' ', array_slice($words, 0, 6)), 0, 36);
    }

    private function flyerPng(AccountProfile $profile, string $caption): string
    {
        $seed = (string) ($profile->description ?: $profile->resolvedNiche() ?: $caption);
        $hue = abs(crc32($seed)) % 360;

        return FlyerPng::make($hue, $this->headline($caption), $this->footer($profile));
    }
}
