<?php

namespace App\Services\TikTok;

/**
 * Caption timing is estimated from character counts. It is not aligned to the spoken words.
 * Safe zones keep text out of TikTok's side buttons and the bottom caption bar: 90px left and right, 200px top, 480px bottom.
 */
class AssBuilder
{
    /**
     * @param  array{n: int, kind: string, narration: string, on_screen_text: string, seconds: float}  $scene
     * @param  array{primary: ?string, secondary: ?string, font: string}  $brand
     */
    public function write(string $path, array $scene, array $brand, bool $captions): void
    {
        $font = ($brand['font'] ?? 'inter') === 'source_sans' ? 'Source Sans 3' : 'Inter';
        $background = $this->background($brand);
        $text = $this->textColour($background);
        $size = ($scene['kind'] ?? '') === 'stat' ? 92 : 64;
        $events = '';
        if ($captions) {
            $chunks = $this->chunks((string) $scene['narration']);
            $weights = array_map(fn ($chunk) => max(1, mb_strlen($chunk)), $chunks);
            $total = array_sum($weights);
            $cursor = 0.0;
            $duration = max(0.4, (float) $scene['seconds']);
            foreach ($chunks as $index => $chunk) {
                $span = $duration * ($weights[$index] / $total);
                $events .= $this->dialogue($cursor, $cursor + $span, SceneText::ass($chunk, 80), 'Caption');
                $cursor += $span;
            }
        }
        $title = SceneText::ass((string) $scene['on_screen_text'], 80);
        if ($title !== '') {
            $events = $this->dialogue(0, (float) $scene['seconds'], $title, 'Title').$events;
        }

        $primary = $this->assColour($text);
        $outline = $this->assColour($background);
        $body = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\nWrapStyle: 0\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\nStyle: Title,{$font},{$size},{$primary},{$primary},{$outline},&H00000000,1,0,0,0,100,100,0,0,1,2,0,8,90,90,200,1\nStyle: Caption,{$font},54,{$primary},{$primary},{$outline},&H64000000,-1,0,0,0,100,100,0,0,1,3,0,2,90,90,480,1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n{$events}";
        file_put_contents($path, $body);
    }

    /**
     * @return list<string>
     */
    public function chunks(string $narration): array
    {
        $words = preg_split('/\s+/u', trim($narration)) ?: [];
        $words = array_values(array_filter($words, fn ($word) => $word !== ''));
        if ($words === []) {
            return [];
        }
        $size = count($words) <= 8 ? 3 : 4;
        $chunks = [];
        foreach (array_chunk($words, $size) as $group) {
            $chunks[] = implode(' ', $group);
        }

        return $chunks;
    }

    private function dialogue(float $start, float $end, string $text, string $style): string
    {
        if ($text === '') {
            return '';
        }

        return 'Dialogue: 0,'.$this->stamp($start).','.$this->stamp($end).",{$style},,0,0,0,,{\\fad(80,80)}{$text}\n";
    }

    private function stamp(float $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $rest = $seconds - ($hours * 3600) - ($minutes * 60);
        $whole = (int) floor($rest);
        $cent = (int) round(($rest - $whole) * 100);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $whole, $cent);
    }

    /**
     * @param  array{primary: ?string, secondary: ?string}  $brand
     */
    private function background(array $brand): string
    {
        $primary = $brand['primary'] ?? null;

        return is_string($primary) && preg_match('/^#[0-9A-Fa-f]{6}$/', $primary) ? $primary : '#101113';
    }

    private function textColour(string $background): string
    {
        $white = $this->ratio($background, '#FFFFFF');
        $ink = $this->ratio($background, '#111111');

        return $white >= $ink ? '#FFFFFF' : '#111111';
    }

    private function ratio(string $a, string $b): float
    {
        $lighter = max($this->luminance($a), $this->luminance($b));
        $darker = min($this->luminance($a), $this->luminance($b));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $linear = array_map(function ($channel) {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    private function assColour(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $r = substr($hex, 0, 2);
        $g = substr($hex, 2, 2);
        $b = substr($hex, 4, 2);

        return '&H00'.strtoupper($b.$g.$r);
    }
}
