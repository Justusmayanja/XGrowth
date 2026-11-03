<?php

namespace App\Services\TikTok;

use App\Models\PlatformSetup;
use App\Models\User;
use App\Support\TikTokVideoCatalog;
use Illuminate\Validation\ValidationException;

/**
 * TikTok video preferences stored on platform_setups.settings.video.
 * Disabled location and contact fields are omitted from downstream input.
 */
final class TikTokVideoPreferences
{
    /**
     * @param  array<string, mixed>  $video
     */
    private function __construct(private readonly array $video) {}

    public static function forUser(User $user): self
    {
        $setup = PlatformSetup::query()
            ->where('user_id', $user->id)
            ->where('platform', 'tiktok')
            ->first();
        $stored = is_array($setup?->settings) ? ($setup->settings['video'] ?? []) : [];

        return new self(self::normalize(is_array($stored) ? $stored : []));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function validate(array $input): self
    {
        $video = self::normalize($input);
        $errors = [];
        if (! TikTokVideoCatalog::typeAvailable($video['type'])) {
            $errors['type'] = 'This video style is not available yet.';
        }
        $brand = $video['brand'];
        foreach (['primary', 'secondary'] as $key) {
            if ($brand[$key] !== null && ! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $brand[$key])) {
                $errors["brand.$key"] = 'Use a colour like #112233.';
            }
        }
        if (mb_strlen((string) $video['context']['location_text']) > 60) {
            $errors['context.location_text'] = 'Location can be up to 60 characters.';
        }
        if (mb_strlen((string) $brand['outro_text']) > 80) {
            $errors['brand.outro_text'] = 'Outro text can be up to 80 characters.';
        }
        $phone = (string) $video['context']['phone'];
        if ($phone !== '' && ! preg_match('/^[0-9+().\-\s]{6,20}$/', $phone)) {
            $errors['context.phone'] = 'Enter a phone number with digits, spaces, or + ( ) . -';
        }
        $website = (string) $video['context']['website'];
        if ($website !== '' && ! preg_match('/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(\/\S*)?$/i', $website)) {
            $errors['context.website'] = 'Enter a website like example.com.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($video);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->video;
    }

    public function type(): string
    {
        return (string) $this->video['type'];
    }

    public function generate(): string
    {
        return (string) $this->video['generate'];
    }

    public function language(): string
    {
        return (string) $this->video['voice']['language'];
    }

    public function lengthTarget(): int
    {
        return $this->video['length'] === TikTokVideoCatalog::LENGTH_SHORT
            ? TikTokVideoCatalog::SHORT_SECONDS
            : TikTokVideoCatalog::STANDARD_SECONDS;
    }

    /**
     * Fields the script writer, scene builder, and renderer are allowed to see.
     *
     * @return array<string, string>
     */
    public function enabledContext(): array
    {
        $out = [];
        $context = $this->video['context'];
        if ($context['mention_location'] && $context['location_text'] !== '') {
            $out['location_text'] = $context['location_text'];
        }
        if ($context['show_contact']) {
            foreach (['business_name', 'website', 'phone'] as $key) {
                if ($context[$key] !== '') {
                    $out[$key] = $context[$key];
                }
            }
        }

        return $out;
    }

    /**
     * @return array{use_logo: bool, use_colors: bool, primary: ?string, secondary: ?string, font: string, outro_text: string}
     */
    public function brand(): array
    {
        return $this->video['brand'];
    }

    public function musicEnabled(): bool
    {
        return (bool) $this->video['music']['enabled'] && self::licensedMusic() !== [];
    }

    /**
     * @return list<string>
     */
    public static function licensedMusic(): array
    {
        $dir = resource_path('video/music');
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir.'/*.{mp3,m4a,wav}', GLOB_BRACE) ?: [];

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $voice = is_array($input['voice'] ?? null) ? $input['voice'] : [];
        $captions = is_array($input['captions'] ?? null) ? $input['captions'] : [];
        $music = is_array($input['music'] ?? null) ? $input['music'] : [];
        $brand = is_array($input['brand'] ?? null) ? $input['brand'] : [];
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];

        $type = (string) ($input['type'] ?? TikTokVideoCatalog::TYPE_MOTION);
        if (! in_array($type, [TikTokVideoCatalog::TYPE_MOTION, TikTokVideoCatalog::TYPE_CARTOON, TikTokVideoCatalog::TYPE_AVATAR, TikTokVideoCatalog::TYPE_STOCK], true)) {
            $type = TikTokVideoCatalog::TYPE_MOTION;
        }
        $style = (string) ($voice['style'] ?? TikTokVideoCatalog::VOICE_FEMALE);
        if (! in_array($style, [TikTokVideoCatalog::VOICE_FEMALE, TikTokVideoCatalog::VOICE_MALE], true)) {
            $style = TikTokVideoCatalog::VOICE_FEMALE;
        }
        $language = strtolower((string) ($voice['language'] ?? TikTokVideoCatalog::LANGUAGE_EN));
        $captionStyle = (string) ($captions['style'] ?? TikTokVideoCatalog::CAPTIONS_BOLD);
        if (! in_array($captionStyle, [TikTokVideoCatalog::CAPTIONS_BOLD, TikTokVideoCatalog::CAPTIONS_CLEAN], true)) {
            $captionStyle = TikTokVideoCatalog::CAPTIONS_BOLD;
        }
        $length = (string) ($input['length'] ?? TikTokVideoCatalog::LENGTH_STANDARD);
        if (! in_array($length, [TikTokVideoCatalog::LENGTH_SHORT, TikTokVideoCatalog::LENGTH_STANDARD], true)) {
            $length = TikTokVideoCatalog::LENGTH_STANDARD;
        }
        $generate = (string) ($input['generate'] ?? TikTokVideoCatalog::GENERATE_AHEAD);
        if (! in_array($generate, [TikTokVideoCatalog::GENERATE_AHEAD, TikTokVideoCatalog::GENERATE_ON_CONFIRM], true)) {
            $generate = TikTokVideoCatalog::GENERATE_AHEAD;
        }
        $font = (string) ($brand['font'] ?? TikTokVideoCatalog::FONT_INTER);
        if (! in_array($font, [TikTokVideoCatalog::FONT_INTER, TikTokVideoCatalog::FONT_SOURCE_SANS], true)) {
            $font = TikTokVideoCatalog::FONT_INTER;
        }

        return [
            'type' => $type,
            'voice' => ['style' => $style, 'language' => $language],
            'captions' => ['enabled' => (bool) ($captions['enabled'] ?? true), 'style' => $captionStyle],
            'length' => $length,
            'music' => ['enabled' => (bool) ($music['enabled'] ?? false)],
            'generate' => $generate,
            'brand' => [
                'use_logo' => (bool) ($brand['use_logo'] ?? false),
                'use_colors' => (bool) ($brand['use_colors'] ?? false),
                'primary' => self::blank($brand['primary'] ?? null),
                'secondary' => self::blank($brand['secondary'] ?? null),
                'font' => $font,
                'outro_text' => mb_substr(trim((string) ($brand['outro_text'] ?? '')), 0, 80),
            ],
            'context' => [
                'mention_location' => (bool) ($context['mention_location'] ?? false),
                'location_text' => mb_substr(trim((string) ($context['location_text'] ?? '')), 0, 60),
                'show_contact' => (bool) ($context['show_contact'] ?? false),
                'business_name' => mb_substr(trim((string) ($context['business_name'] ?? '')), 0, 80),
                'website' => mb_substr(trim((string) ($context['website'] ?? '')), 0, 120),
                'phone' => mb_substr(trim((string) ($context['phone'] ?? '')), 0, 20),
            ],
        ];
    }

    private static function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
