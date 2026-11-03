<?php

namespace App\Services\TikTok;

use App\Models\TikTokClip;
use App\Support\TikTokVideoCatalog;

/**
 * Turns an already-written script into scenes. No model call.
 */
class SceneBuilder
{
    /**
     * @param  array<string, string>  $enabled
     * @return array{scenes: list<array{n: int, kind: string, narration: string, on_screen_text: string, seconds_estimate: int}>, voice: array{style: string, language: string}, brand: array<string, mixed>, format: array{width: int, height: int, fps: int}}
     */
    public function spec(TikTokClip $clip, TikTokVideoPreferences $preferences, array $enabled): array
    {
        $lines = $this->lines($clip);
        $target = min(TikTokVideoCatalog::MAX_SECONDS, $preferences->lengthTarget());
        $bodies = $this->fit($lines, $target);
        $scenes = [];
        $count = count($bodies);
        foreach ($bodies as $index => $line) {
            $kind = $this->kind($line, $index, $count);
            $scenes[] = [
                'n' => $index + 1,
                'kind' => $kind,
                'narration' => SceneText::plain($line, 180),
                'on_screen_text' => SceneText::plain($this->onScreen($clip, $line, $kind, $enabled), 80),
                'seconds_estimate' => max(2, (int) floor($target / $count)),
            ];
        }

        $package = [
            'scenes' => $scenes,
            'voice' => [
                'style' => $preferences->toArray()['voice']['style'],
                'language' => $preferences->language(),
            ],
            'brand' => $this->brand($preferences, $enabled),
            'format' => [
                'width' => TikTokVideoCatalog::WIDTH,
                'height' => TikTokVideoCatalog::HEIGHT,
                'fps' => TikTokVideoCatalog::FPS,
            ],
        ];

        $stored = $clip->package;
        $stored['scenes'] = $scenes;
        $clip->package = $stored;
        $clip->save();

        return $package;
    }

    /**
     * @return list<string>
     */
    private function lines(TikTokClip $clip): array
    {
        $script = trim((string) $clip->script);
        $lines = array_values(array_filter(array_map(
            fn ($line) => SceneText::plain((string) $line, 180),
            preg_split("/\r\n|\n|\r/", $script) ?: [],
        ), fn ($line) => $line !== ''));
        if ($lines === []) {
            $hook = SceneText::plain((string) $clip->hook, 180);
            $lines = [$hook !== '' ? $hook : 'Here is the point.'];
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function fit(array $lines, int $target): array
    {
        $maxScenes = $target <= TikTokVideoCatalog::SHORT_SECONDS ? 4 : 6;
        while (count($lines) > $maxScenes) {
            $last = array_pop($lines);
            $lines[count($lines) - 1] = SceneText::plain($lines[count($lines) - 1].' '.$last, 180);
        }
        while (count($lines) < 3) {
            $lines[] = 'That is the whole point.';
        }

        return array_slice($lines, 0, 7);
    }

    private function kind(string $line, int $index, int $count): string
    {
        if ($index === $count - 1) {
            return 'outro';
        }
        if ($index === 0) {
            return 'statement';
        }
        if (preg_match('/\d/', $line) === 1 && preg_match('/\d{2,}|%|\$/', $line) === 1) {
            return 'stat';
        }
        if (preg_match('/^(1\.|2\.|3\.|First|Second|Third|Next)\b/i', $line) === 1) {
            return 'list_item';
        }
        if (str_contains($line, '?')) {
            return 'qa';
        }

        return 'statement';
    }

    /**
     * @param  array<string, string>  $enabled
     */
    private function onScreen(TikTokClip $clip, string $line, string $kind, array $enabled): string
    {
        if ($kind === 'outro') {
            $parts = [];
            $outro = trim((string) ($clip->package['brand']['outro_text'] ?? ''));
            if ($outro !== '') {
                $parts[] = $outro;
            }
            foreach (['location_text', 'business_name', 'website', 'phone'] as $key) {
                if (isset($enabled[$key]) && $enabled[$key] !== '') {
                    $parts[] = $enabled[$key];
                }
            }

            return $parts === [] ? $line : implode(' · ', $parts);
        }

        $screen = $clip->package['on_screen_text'] ?? [];
        if (is_array($screen) && isset($screen[0]) && is_string($screen[0]) && $screen[0] !== '') {
            return $kind === 'statement' && $line === ($this->lines($clip)[0] ?? '') ? $screen[0] : $line;
        }

        return $line;
    }

    /**
     * @param  array<string, string>  $enabled
     * @return array<string, mixed>
     */
    private function brand(TikTokVideoPreferences $preferences, array $enabled): array
    {
        $brand = $preferences->brand();
        if (! $brand['use_colors']) {
            $brand['primary'] = null;
            $brand['secondary'] = null;
        }
        if (! $brand['use_logo']) {
            $brand['use_logo'] = false;
        }
        $brand['enabled'] = $enabled;

        return $brand;
    }
}
