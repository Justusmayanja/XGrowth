<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;
use Illuminate\Support\Str;

class YouTubeEditPlanner
{
    /**
     * The cut is fully specified here. Applying it to the file waits for the editor.
     *
     * @return array<string, mixed>
     */
    public function plan(AccountProfile $profile, string $filename, ?string $note): array
    {
        $niche = $profile->resolvedNiche();
        $captions = $this->captions($note);
        $title = $captions[0]['text'] ?? ('Edit of '.pathinfo($filename, PATHINFO_FILENAME));

        return [
            'title' => Str::limit($title, 60, ''),
            'description' => "Edited from footage about {$niche}.\nCaptions, a few pushes, and a cleaner picture.\n\n".$profile->audience,
            'captions' => $captions,
            'caption_style' => 'Two or three words at a time. The word being said is highlighted.',
            'caption_note' => $captions === []
                ? 'Word timings are filled when the transcript step is connected. The caption style is already set.'
                : 'These lines come from the note. Exact word timings are filled when the transcript step is connected.',
            'zooms' => [
                ['at_ratio' => 0.08, 'scale' => 1.08, 'hold_seconds' => 1.2, 'reason' => 'Opening line'],
                ['at_ratio' => 0.42, 'scale' => 1.14, 'hold_seconds' => 1.4, 'reason' => 'The point of the video'],
                ['at_ratio' => 0.72, 'scale' => 1.10, 'hold_seconds' => 1.2, 'reason' => 'Last beat'],
            ],
            'color' => [
                'preset' => 'clean',
                'contrast' => 1.08,
                'saturation' => 1.04,
                'brightness' => 0.02,
                'warmth' => 0.03,
            ],
            'audio' => ['loudness_lufs' => -14],
            'silence' => ['trim_over_seconds' => 0.6],
            'render' => [
                'provider' => 'pending',
                'ready' => false,
                'kind' => 'edit',
                'note' => 'The cut is specified. The edited file is made when the editor is connected.',
            ],
        ];
    }

    /**
     * @return list<array{text: string, at: string}>
     */
    private function captions(?string $note): array
    {
        $note = trim((string) $note);
        if ($note === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+|\n+/', $note) ?: [];
        $lines = [];
        $second = 0;
        foreach ($parts as $part) {
            $text = trim($part);
            if ($text === '') {
                continue;
            }
            $lines[] = ['text' => Str::limit($text, 80, ''), 'at' => sprintf('0:%02d', $second)];
            $second += 3;
            if (count($lines) >= 12) {
                break;
            }
        }

        return $lines;
    }
}
