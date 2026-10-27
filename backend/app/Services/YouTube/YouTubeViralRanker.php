<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;

/**
 * Rank public listings by how far each video beat its own channel's median.
 * A channel with fewer than four videos is skipped. The formula is a read of the title.
 */
class YouTubeViralRanker
{
    public function __construct(
        private readonly YouTubeHookScorer $hooks,
        private readonly YouTubeWords $words,
    ) {}

    /**
     * @param  list<array{channel?: string, title?: string, views?: int|float, url?: string, duration?: int}>  $videos
     * @return array{outliers: list<array<string, mixed>>, skipped: list<array{channel: string, count: int}>, shared: string, note: string}
     */
    public function rank(array $videos): array
    {
        $byChannel = [];
        foreach ($videos as $video) {
            $channel = trim((string) ($video['channel'] ?? ''));
            if ($channel === '' || trim((string) ($video['title'] ?? '')) === '') {
                continue;
            }
            $byChannel[$channel][] = $video;
        }

        $outliers = [];
        $skipped = [];
        foreach ($byChannel as $channel => $group) {
            if (count($group) < 4) {
                $skipped[] = ['channel' => $channel, 'count' => count($group)];
                continue;
            }
            $views = array_map(fn (array $video) => (float) ($video['views'] ?? 0), $group);
            $median = $this->median($views);
            if ($median <= 0) {
                continue;
            }
            foreach ($group as $video) {
                $count = (float) ($video['views'] ?? 0);
                $multiple = $count / $median;
                if ($multiple <= 1) {
                    continue;
                }
                $title = trim((string) $video['title']);
                $outliers[] = [
                    'channel' => $channel,
                    'title' => $title,
                    'views' => (int) $count,
                    'median' => (int) round($median),
                    'multiple' => round($multiple, 2),
                    'formula' => $this->hooks->formulaName($title),
                    'url' => (string) ($video['url'] ?? ''),
                ];
            }
        }

        usort($outliers, fn (array $a, array $b) => $b['multiple'] <=> $a['multiple']);

        return [
            'outliers' => $outliers,
            'skipped' => $skipped,
            'shared' => $this->shared(array_slice($outliers, 0, 5)),
            'note' => 'The formula is a read of the title, not a claim about why the video worked.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $top
     * @return list<array<string, mixed>>
     */
    public function makeable(array $top, AccountProfile $profile): array
    {
        $boundary = $this->words->content(
            $profile->resolvedNiche().' '.$profile->description.' '.$profile->audience
        );
        $made = [];
        foreach ($top as $video) {
            $titleWords = $this->words->content((string) $video['title']);
            $overlap = array_values(array_intersect($titleWords, $boundary));
            $long = array_filter($overlap, fn (string $word) => strlen($word) >= 6);
            if (count($overlap) < 2 && $long === []) {
                continue;
            }
            if ($this->needsSomethingTheyDoNotHave((string) $video['title'], (string) $profile->description)) {
                continue;
            }
            $have = array_slice($overlap, 0, 4);
            $video['have'] = implode(', ', $have);
            $video['idea'] = $this->inVoice($have, (string) ($profile->primaryTone() ?: 'plain'));
            $video['voice'] = $profile->primaryTone();
            $made[] = $video;
        }

        return $made;
    }

    /** @param  list<string>  $have */
    private function inVoice(array $have, string $tone): string
    {
        $subject = implode(' ', $have);
        if ($subject === '') {
            return '';
        }

        return ucfirst($subject).', said in a '.$tone.' voice with what this channel already uses';
    }

    private function needsSomethingTheyDoNotHave(string $title, string $description): bool
    {
        $titleHasMoney = preg_match('/\$\s?\d|\b(studio team|hired crew|expensive)\b/i', $title) === 1;
        $descriptionHasMoney = preg_match('/\$\s?\d|\bexpensive\b/i', $description) === 1;

        return $titleHasMoney && ! $descriptionHasMoney;
    }

    /** @param  list<array<string, mixed>>  $top */
    private function shared(array $top): string
    {
        if (count($top) < 2) {
            return 'Not enough videos over their own median yet to name one shared structure.';
        }

        $counts = [];
        foreach ($top as $video) {
            $formula = (string) ($video['formula'] ?? 'Unclassified');
            $counts[$formula] = ($counts[$formula] ?? 0) + 1;
        }
        arsort($counts);
        $name = (string) array_key_first($counts);
        $count = $counts[$name];
        if ($name !== 'Unclassified' && $count >= 2) {
            return "The titles share {$name}. That is a read of the wording, not a claim about why the videos worked.";
        }

        $questions = 0;
        foreach ($top as $video) {
            if (str_ends_with(trim((string) $video['title']), '?')) {
                $questions++;
            }
        }
        if ($questions >= 2) {
            return 'The titles are questions. That is a read of the wording, not a claim about why the videos worked.';
        }

        return 'These titles do not share one formula. The multiple over each channel median is the signal. The formula line is only a read of the wording.';
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
