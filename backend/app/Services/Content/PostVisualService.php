<?php

namespace App\Services\Content;

use App\Models\AccountProfile;
use App\Models\User;
use App\Services\AICore\AICoreService;
use App\Services\ApiSpend\ApiSpendService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A picture for an X post, once the last few posts were only words.
 * Mackie17 writes the image prompt. The image model paints it.
 * A short line sits on a top bar. The picture fills the space under it.
 * If the model is unavailable and no kind was chosen, a drawn card is the fallback.
 */
class PostVisualService
{
    public function __construct(
        private readonly ApiSpendService $budget,
        private readonly AICoreService $ai,
    ) {}

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
     * @param  'flyer'|'photo'|'illustration'|null  $kind
     * @return array{path: string, kind: string}|null
     */
    public function make(User $user, ?AccountProfile $profile, string $caption, ?string $kind = null): ?array
    {
        $about = $profile ?? new AccountProfile;
        $requested = $this->normalizeKind($kind) ?? $this->nextKind($user);
        $bytes = $this->illustration($user, $about, $caption, $requested);
        $used = $requested;
        if ($bytes === null && $kind === null) {
            $bytes = $this->flyerPng($about, $caption);
            $used = 'flyer';
        } elseif (is_string($bytes)) {
            $bytes = $this->captionOnTop($bytes, $caption, $about);
        }

        if ($bytes === null) {
            return null;
        }

        $extension = str_starts_with($bytes, "\xFF\xD8") ? 'jpg' : 'png';
        $path = 'post-visuals/'.$user->id.'/'.str_replace('-', '', (string) Str::uuid()).'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        return ['path' => $path, 'kind' => $used];
    }

    /**
     * Keep a picture on disk until the draft that shows it is saved.
     *
     * @param  'flyer'|'photo'|'illustration'  $kind
     * @return array{id: string, path: string, kind: string}|null
     */
    public function preview(User $user, ?AccountProfile $profile, string $caption, string $kind): ?array
    {
        $this->clearUnusedPreviews($user);
        $made = $this->make($user, $profile, $caption, $kind);
        if ($made === null) {
            return null;
        }

        $id = str_replace('-', '', (string) Str::uuid());
        Storage::disk('local')->put($this->previewMetaPath($user, $id), json_encode([
            'path' => $made['path'],
            'kind' => $made['kind'],
        ], JSON_THROW_ON_ERROR));

        return ['id' => $id, 'path' => $made['path'], 'kind' => $made['kind']];
    }

    /**
     * @return array{path: string, kind: string}|null
     */
    public function readPreview(User $user, string $id): ?array
    {
        $meta = $this->previewMeta($user, $id);
        if ($meta === null || ! Storage::disk('local')->exists($meta['path'])) {
            return null;
        }

        return $meta;
    }

    public function forgetPreview(User $user, string $id): void
    {
        if (! $this->validPreviewId($id)) {
            return;
        }

        Storage::disk('local')->delete($this->previewMetaPath($user, $id));
    }

    private function normalizeKind(?string $kind): ?string
    {
        return match ($kind) {
            'flyer' => 'flyer',
            'photo' => 'photo',
            'illustration', 'image' => 'illustration',
            default => null,
        };
    }

    private function nextKind(User $user): string
    {
        $count = $user->posts()->whereNotNull('media_path')->count();

        return $count % 2 === 0 ? 'illustration' : 'flyer';
    }

    private function illustration(User $user, AccountProfile $profile, string $caption, string $kind = 'illustration'): ?string
    {
        if (app()->runningUnitTests() && config('openai.allow_image_http') !== true) {
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
                ->timeout(45)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => 'dall-e-3',
                    'prompt' => $this->picturePrompt($user, $profile, $caption, $kind),
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

    private function picturePrompt(User $user, AccountProfile $profile, string $caption, string $kind): string
    {
        $drafted = null;
        try {
            $drafted = $this->ai->draftPicturePrompt($user, $profile, $caption, $kind);
        } catch (Throwable) {
            $drafted = null;
        }

        $prompt = is_string($drafted) && $drafted !== ''
            ? $drafted
            : $this->imagePrompt($profile, $caption, $kind);

        return $this->constrainPrompt($prompt, $kind);
    }

    private function constrainPrompt(string $prompt, string $kind): string
    {
        $prompt = trim((string) preg_replace('/\s+/', ' ', $prompt));
        $prompt = mb_substr($prompt, 0, 900);
        $tail = match ($kind) {
            'photo' => ' Photorealistic, natural light, one subject. No words, letters, numbers, logos, or watermarks. Not an illustration.',
            'flyer' => ' A bold graphic flyer, flat color, high contrast, empty band across the top. No words, letters, numbers, logos, or watermarks. Not a photograph.',
            default => ' A bold simple illustration, high contrast. No words, letters, numbers, logos, or watermarks. Not a photograph.',
        };

        return $prompt.$tail;
    }

    private function imagePrompt(AccountProfile $profile, string $caption, string $kind = 'illustration'): string
    {
        $about = trim((string) $profile->description);
        if ($about === '') {
            $about = trim((string) $profile->resolvedNiche());
        }
        $about = mb_substr((string) preg_replace('/\s+/', ' ', $about), 0, 400);
        $audience = mb_substr(trim((string) $profile->audience), 0, 160);
        $moment = mb_substr((string) preg_replace('/\s+/', ' ', $caption), 0, 180);

        $prompt = match ($kind) {
            'photo' => 'A single original photograph for a social account. Photorealistic, natural light, one clear subject. Not an illustration, not a cartoon, not a screenshot, and not a collage of text. ',
            'flyer' => 'A single original flyer for a social account. Flat color, one bold shape, high contrast, empty space across the top. Not a photograph and not a collage of text. ',
            default => 'A single original illustration for a social account. Bold, simple, high contrast. Not a photograph, not a screenshot, and not a collage of text. ',
        };
        $prompt .= "Show the world of this account: {$about}. ";
        if ($audience !== '') {
            $prompt .= "The people it is for: {$audience}. ";
        }
        $prompt .= "The moment, shown without writing it: {$moment}. ";

        return $prompt;
    }

    /**
     * A short line about the picture, not a copy of the post.
     * The tweet stays the take. This is only a brief label, like on X.
     */
    public function overlayLine(string $caption, ?AccountProfile $profile): string
    {
        $tweet = $this->plain($caption);
        foreach ([$this->fromAccount($profile), $this->fromNiche($profile)] as $line) {
            if ($line !== '' && ! $this->sameSense($line, $tweet)) {
                return $line;
            }
        }

        $topic = $this->shortTopic($tweet);
        if ($topic !== '' && ! $this->sameSense($topic, $tweet)) {
            return $topic;
        }

        return $this->fromAccount($profile) ?: $this->fromNiche($profile) ?: 'Today';
    }

    public function headline(string $caption, ?AccountProfile $profile = null): string
    {
        return $this->overlayLine($caption, $profile);
    }

    private function flyerPng(AccountProfile $profile, string $caption): string
    {
        return FlyerPng::make($this->hue($profile, $caption), $this->overlayLine($caption, $profile), '');
    }

    private function captionOnTop(string $bytes, string $caption, AccountProfile $profile): string
    {
        $decoded = PngRgb::decode($bytes);
        if ($decoded === null) {
            return $bytes;
        }

        return FlyerPng::withPhoto(
            $this->hue($profile, $caption),
            $this->overlayLine($caption, $profile),
            $decoded['rgb'],
            $decoded['width'],
            $decoded['height'],
        );
    }

    private function fromAccount(?AccountProfile $profile): string
    {
        $source = $this->plain((string) ($profile?->description ?? ''));
        if ($source === '') {
            return '';
        }

        $clause = trim((string) (preg_split('/[,.]/u', $source)[0] ?? ''));

        return $this->fewWords($clause, 4);
    }

    private function fromNiche(?AccountProfile $profile): string
    {
        $niche = $this->plain((string) ($profile?->resolvedNiche() ?? ''));
        if ($niche === '' || $niche === 'general') {
            return '';
        }

        return $this->fewWords($niche, 4);
    }

    private function shortTopic(string $tweet): string
    {
        $stop = ['the', 'and', 'for', 'that', 'this', 'with', 'from', 'have', 'just', 'about', 'your', 'you', 'are', 'was', 'not', 'but', 'they', 'their', 'is', 'it'];
        $words = [];
        foreach (preg_split('/\s+/', $this->plain($tweet)) ?: [] as $word) {
            if (mb_strlen($word) < 3 || in_array(mb_strtolower($word), $stop, true)) {
                continue;
            }
            $words[] = $word;
            if (count($words) === 2) {
                break;
            }
        }

        $line = implode(' ', $words);

        return mb_strlen($line) >= 6 ? $line : '';
    }

    private function fewWords(string $text, int $max): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', $this->plain($text)) ?: [], fn (string $word) => $word !== ''));
        $stop = ['for', 'with', 'and', 'the', 'a', 'an', 'from', 'that', 'this', 'into', 'on', 'is', 'are'];
        $keep = [];
        foreach ($words as $word) {
            if (count($keep) >= $max) {
                break;
            }
            $lower = mb_strtolower($word);
            if ($keep !== [] && in_array($lower, $stop, true)) {
                break;
            }
            if ($keep === [] && in_array($lower, $stop, true)) {
                continue;
            }
            $keep[] = $word;
        }

        return implode(' ', $keep);
    }

    private function sameSense(string $line, string $tweet): bool
    {
        $a = mb_strtolower($this->plain($line));
        $b = mb_strtolower($this->plain($tweet));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($b, $a)) {
            return true;
        }

        return false;
    }

    private function plain(string $text): string
    {
        $text = trim((string) preg_replace('/https?:\/\/\S+|[#@]\S+/', '', $text));
        $text = trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function hue(AccountProfile $profile, string $caption): int
    {
        $seed = (string) ($profile->description ?: $profile->resolvedNiche() ?: $caption);

        return abs(crc32($seed)) % 360;
    }

    private function clearUnusedPreviews(User $user): void
    {
        $dir = 'post-visuals/'.$user->id.'/previews';
        if (! Storage::disk('local')->directoryExists($dir)) {
            return;
        }

        foreach (Storage::disk('local')->files($dir) as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }
            $decoded = json_decode((string) Storage::disk('local')->get($file), true);
            Storage::disk('local')->delete($file);
            $path = is_array($decoded) ? ($decoded['path'] ?? null) : null;
            if (! is_string($path) || ! str_starts_with($path, 'post-visuals/'.$user->id.'/')) {
                continue;
            }
            if ($user->posts()->where('media_path', $path)->exists()) {
                continue;
            }
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * @return array{path: string, kind: string}|null
     */
    private function previewMeta(User $user, string $id): ?array
    {
        if (! $this->validPreviewId($id)) {
            return null;
        }

        $metaPath = $this->previewMetaPath($user, $id);
        if (! Storage::disk('local')->exists($metaPath)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($metaPath), true);
        $path = is_array($decoded) ? ($decoded['path'] ?? null) : null;
        if (! is_string($path) || ! str_starts_with($path, 'post-visuals/'.$user->id.'/')) {
            return null;
        }

        return [
            'path' => $path,
            'kind' => is_string($decoded['kind'] ?? null) ? $decoded['kind'] : '',
        ];
    }

    private function previewMetaPath(User $user, string $id): string
    {
        return 'post-visuals/'.$user->id.'/previews/'.$id.'.json';
    }

    private function validPreviewId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }
}
