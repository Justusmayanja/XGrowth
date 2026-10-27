<?php

namespace App\Services\YouTube;

/**
 * YouTube only renders a chapter list that starts at 0:00, has at least three
 * entries, and gives every chapter at least 10 seconds.
 */
class YouTubeChapterWriter
{
    public function __construct(private readonly YouTubeWords $words) {}

    /**
     * @param  list<array{title: string, words: int}>  $sections
     * @return array{valid: bool, chapters: list<array{start: int, label: string, title: string, seconds: int}>, duration: int}
     */
    public function fromSections(array $sections): array
    {
        $cursor = 0;
        $chapters = [];
        foreach ($sections as $section) {
            $words = max(1, (int) $section['words']);
            $seconds = max(10, (int) round($words / 150 * 60));
            $chapters[] = [
                'start' => $cursor,
                'label' => $this->label($cursor),
                'title' => $this->promise($section['title']),
                'seconds' => $seconds,
            ];
            $cursor += $seconds;
        }

        $valid = $this->valid($chapters);

        return [
            'valid' => $valid,
            'chapters' => $chapters,
            'duration' => $cursor,
        ];
    }

    /**
     * @param  list<array{start?: int, at?: int|string, seconds?: int, label?: string, title?: string}>  $chapters
     */
    public function valid(array $chapters, ?int $duration = null): bool
    {
        if (count($chapters) < 3) {
            return false;
        }

        $starts = [];
        foreach ($chapters as $chapter) {
            $starts[] = $this->startOf($chapter);
        }
        if ($starts[0] !== 0) {
            return false;
        }

        $end = $duration ?? ($starts[count($starts) - 1] + (int) ($chapters[count($chapters) - 1]['seconds'] ?? 0));
        for ($i = 0; $i < count($starts); $i++) {
            $next = $i + 1 < count($starts) ? $starts[$i + 1] : $end;
            if ($next - $starts[$i] < 10) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{start: int, label: string, title: string, seconds: int}>  $chapters
     */
    public function block(array $chapters): string
    {
        $lines = [];
        foreach ($chapters as $chapter) {
            $lines[] = $chapter['label'].' '.$chapter['title'];
        }

        return implode("\n", $lines);
    }

    /** @param  array{start?: int, at?: int|string, label?: string}  $chapter */
    public function startOf(array $chapter): int
    {
        if (isset($chapter['start'])) {
            return (int) $chapter['start'];
        }
        if (isset($chapter['at']) && is_numeric($chapter['at'])) {
            return (int) $chapter['at'];
        }
        if (isset($chapter['label']) || isset($chapter['at'])) {
            return $this->seconds((string) ($chapter['label'] ?? $chapter['at']));
        }

        return -1;
    }

    public function label(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hour = intdiv($seconds, 3600);
        $minute = intdiv($seconds % 3600, 60);
        $second = $seconds % 60;

        return $hour > 0
            ? sprintf('%d:%02d:%02d', $hour, $minute, $second)
            : sprintf('%d:%02d', $minute, $second);
    }

    public function seconds(string $label): int
    {
        $parts = array_map('intval', explode(':', trim($label)));
        if (count($parts) === 3) {
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }

        return (int) ($parts[0] ?? -1);
    }

    public function promise(string $title): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $parts = $clean === '' ? [] : explode(' ', $clean);
        if (count($parts) > 5) {
            $parts = array_slice($parts, 0, 5);
        }
        while (count($parts) < 3) {
            $parts[] = 'section';
        }

        return implode(' ', $parts);
    }
}
