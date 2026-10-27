<?php

namespace App\Services\YouTube;

/**
 * A Short is a span of a finished long video that survives without the video around it.
 * It is not a niche template.
 */
class YouTubeShortsCutter
{
    public function __construct(
        private readonly YouTubeHookScorer $hooks,
        private readonly YouTubeWords $words,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function cut(string $transcript): array
    {
        $cues = $this->cues($transcript);
        if (count($cues) < 3) {
            return [];
        }

        $candidates = [];
        $count = count($cues);
        for ($start = 0; $start < $count; $start++) {
            if (! $this->opensClean($cues[$start][2], $start === 0 || $this->ended($cues[$start - 1][2]))) {
                continue;
            }
            for ($end = $start; $end < $count; $end++) {
                $span = $cues[$end][1] - $cues[$start][0];
                if ($span < 20) {
                    continue;
                }
                if ($span > 55) {
                    break;
                }
                if (! $this->ended($cues[$end][2])) {
                    continue;
                }
                $text = [];
                for ($i = $start; $i <= $end; $i++) {
                    $text[] = $cues[$i][2];
                }
                $body = implode(' ', $text);
                if (preg_match('/\b(but|except|actually|instead|wrong|until|however)\b/i', $body) !== 1) {
                    continue;
                }
                $candidates[] = [
                    'start' => $cues[$start][0],
                    'end' => $cues[$end][1],
                    'first' => $cues[$start][2],
                    'last' => $cues[$end][2],
                    'body' => $body,
                    'span' => $span,
                ];
            }
        }

        usort($candidates, fn (array $a, array $b) => $b['span'] <=> $a['span']);
        $picked = [];
        foreach ($candidates as $candidate) {
            $overlaps = false;
            foreach ($picked as $kept) {
                if ($candidate['start'] < $kept['end'] && $candidate['end'] > $kept['start']) {
                    $overlaps = true;
                    break;
                }
            }
            if ($overlaps) {
                continue;
            }
            $picked[] = $candidate;
            if (count($picked) === 5) {
                break;
            }
        }

        return array_map(fn (array $candidate) => $this->write($candidate), $picked);
    }

    /**
     * @param  array{start: float, end: float, first: string, last: string, body: string, span: float}  $candidate
     * @return array<string, mixed>
     */
    private function write(array $candidate): array
    {
        $opening = $this->opening($candidate['body'], $candidate['first']);
        $scored = $this->hooks->score($opening);
        $spokenWords = $this->words->content($opening);
        $onScreen = $this->onScreen($spokenWords);

        return [
            'start' => $this->clock($candidate['start']),
            'end' => $this->clock($candidate['end']),
            'seconds' => (int) round($candidate['span']),
            'first_line' => $candidate['first'],
            'opening' => [
                'text' => $scored['hook'],
                'score' => $scored['verdict'],
                'formula' => $scored['formula'],
                'band' => $scored['band'],
            ],
            'on_screen' => $onScreen,
            'loop' => 'The last line ("'.$this->words->cut($candidate['last'], 80).'") sets up the opening, so the first line answers it when the Short loops.',
            'crop' => 'A vertical crop cuts both sides of the 16:9 frame. A wide counter, a second person, or anything sitting at the left or right edge leaves the picture.',
        ];
    }

    private function opening(string $body, string $first): string
    {
        $claim = $this->words->cut(trim($body), 110);
        $line = 'Here is the part that stands without the rest of the video. '.$claim;
        $line = $this->words->limitWords($line, 24);
        if (strtolower($line) === strtolower($first)) {
            $line = 'Stand-alone: '.$this->words->limitWords($claim, 18);
        }

        return $line;
    }

    /** @param  list<string>  $spoken */
    private function onScreen(array $spoken): string
    {
        foreach (['Hold the frame', 'See the turn', 'Read this once', 'Stay for this'] as $option) {
            $overlap = array_intersect($this->words->content($option), $spoken);
            if ($overlap === [] && count($this->words->tokens($option)) <= 3) {
                return $option;
            }
        }

        return 'Hold the frame';
    }

    /**
     * @return list<array{0: float, 1: float, 2: string}>
     */
    public function cues(string $transcript): array
    {
        $transcript = trim($transcript);
        if ($transcript === '') {
            return [];
        }
        if (str_contains($transcript, '-->')) {
            return $this->srt($transcript);
        }

        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $transcript) ?: [] as $line) {
            if (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)\s+(.+)$/', trim($line), $match) === 1) {
                $lines[] = [$this->seconds($match[1]), trim($match[2])];
            }
        }
        $cues = [];
        foreach ($lines as $index => [$start, $text]) {
            $end = isset($lines[$index + 1]) ? $lines[$index + 1][0] : $start + 4;
            if ($end <= $start) {
                $end = $start + 4;
            }
            $cues[] = [(float) $start, (float) $end, $text];
        }

        return $cues;
    }

    /**
     * @return list<array{0: float, 1: float, 2: string}>
     */
    private function srt(string $transcript): array
    {
        $blocks = preg_split('/\r\n\r\n|\n\n/', trim($transcript)) ?: [];
        $cues = [];
        foreach ($blocks as $block) {
            $rows = preg_split('/\r\n|\n|\r/', trim($block)) ?: [];
            $time = null;
            $text = [];
            foreach ($rows as $row) {
                if (preg_match('/(\d{1,2}:\d{2}:\d{2}[,.]\d+)\s+-->\s+(\d{1,2}:\d{2}:\d{2}[,.]\d+)/', $row, $match) === 1) {
                    $time = [$match[1], $match[2]];
                    continue;
                }
                if ($time !== null && ! preg_match('/^\d+$/', trim($row))) {
                    $text[] = trim($row);
                }
            }
            if ($time === null || $text === []) {
                continue;
            }
            $cues[] = [$this->seconds($time[0]), $this->seconds($time[1]), trim(implode(' ', $text))];
        }

        return $cues;
    }

    private function seconds(string $label): float
    {
        $label = str_replace(',', '.', $label);
        $parts = explode(':', $label);
        if (count($parts) === 3) {
            return ((int) $parts[0]) * 3600 + ((int) $parts[1]) * 60 + (float) $parts[2];
        }

        return ((int) $parts[0]) * 60 + (float) ($parts[1] ?? 0);
    }

    private function opensClean(string $text, bool $boundary): bool
    {
        if (! $boundary) {
            return false;
        }

        return preg_match('/^(and|but|so|because|or)\b/i', trim($text)) !== 1;
    }

    private function ended(string $text): bool
    {
        return preg_match('/[.?!]["\']?$/', trim($text)) === 1;
    }

    private function clock(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $minute = intdiv($seconds, 60);
        $second = $seconds % 60;

        return sprintf('%d:%02d', $minute, $second);
    }
}
