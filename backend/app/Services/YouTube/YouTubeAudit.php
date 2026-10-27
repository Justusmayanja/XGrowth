<?php

namespace App\Services\YouTube;

use App\Models\YouTubeVideo;
use Illuminate\Support\Collection;

/**
 * One fix for this week, three specific things that are already working,
 * and what not to do yet. Retention numbers stay off this report.
 */
class YouTubeAudit
{
    public function __construct(
        private readonly YouTubeTitleLinter $titles,
        private readonly YouTubeHookScorer $hooks,
        private readonly YouTubeWords $words,
    ) {}

    /**
     * @param  Collection<int, YouTubeVideo>  $videos
     * @return array{fix: string, working: list<string>, not_yet: string}
     */
    public function audit(Collection $videos): array
    {
        $videos = $videos->sortByDesc('id')->values();
        if ($videos->isEmpty()) {
            return [
                'fix' => 'Collect a public listing of the niche, then ship the one piece it supports.',
                'working' => [],
                'not_yet' => 'Do not invent a weekly theme, and do not write a Short from a niche template.',
            ];
        }

        $recent = $videos->take(10);
        $unreadable = 0;
        foreach ($recent as $video) {
            $thumb = (string) ($video->package['thumbnail'] ?? '');
            $title = (string) $video->title;
            if ($this->titles->episodeLabel($thumb) || count($this->words->tokens($thumb)) > 3) {
                $unreadable++;
                continue;
            }
            $checked = $this->titles->check($title, $thumb);
            foreach ($checked['issues'] as $issue) {
                if (in_array($issue[0], ['duplicate', 'thumb-length'], true)) {
                    $unreadable++;
                    break;
                }
            }
        }

        $weakOpen = 0;
        foreach ($videos->take(3) as $video) {
            $opening = $this->opening((string) $video->script);
            if ($opening === '') {
                $weakOpen++;
                continue;
            }
            if ($this->hooks->score($opening)['verdict'] < 55) {
                $weakOpen++;
            }
        }

        $rhythm = $this->rhythm($videos);
        $sameShape = $this->sameShape($recent);

        $fix = match (true) {
            $unreadable >= 3 => 'The thumbnails fail at feed size. This week, rewrite the three words and the contrast. Nothing else yet.',
            $weakOpen >= 2 => 'The first fifteen seconds of the recent videos are the leak. Rewrite that opening before the next upload.',
            $rhythm !== null => $rhythm,
            $sameShape => 'The last titles are the same shape. That is a format problem. Change the shape of the next title, not every word.',
            default => 'Post the piece in front of you, then leave a gap. A burst of uploads is not a rhythm.',
        };

        return [
            'fix' => $fix,
            'working' => $this->working($videos->first()),
            'not_yet' => $unreadable >= 3
                ? 'Do not rework the series outline this week. The feed cannot read the thumbnail.'
                : 'Do not cut Shorts until a transcript is on the long video, and do not open a second format.',
        ];
    }

    /** @param  Collection<int, YouTubeVideo>  $videos */
    private function rhythm(Collection $videos): ?string
    {
        if ($videos->count() < 2) {
            return 'Post the piece in front of you, then wait. One upload is not a rhythm, and a pile of them would be worse.';
        }

        $dates = $videos->sortBy('created_at')->pluck('created_at')->filter()->values();
        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = abs($dates[$i]->getTimestamp() - $dates[$i - 1]->getTimestamp()) / 86400;
        }
        if ($gaps === []) {
            return null;
        }
        $burst = min($gaps) <= 2 && max($gaps) >= 21;

        return $burst
            ? 'Uploads are bunched, then silent. This week, post once and keep that gap. Frequency is not the fix.'
            : null;
    }

    /** @param  Collection<int, YouTubeVideo>  $videos */
    private function sameShape(Collection $videos): bool
    {
        $shapes = [];
        foreach ($videos as $video) {
            $tokens = $this->words->tokens((string) $video->title);
            $shapes[] = implode(' ', array_slice($tokens, 0, 2));
        }
        $shapes = array_filter($shapes);
        if (count($shapes) < 4) {
            return false;
        }
        $counts = array_count_values($shapes);

        return max($counts) >= (int) ceil(count($shapes) / 2);
    }

    /** @return list<string> */
    private function working(?YouTubeVideo $video): array
    {
        if (! $video) {
            return [];
        }
        $notes = [];
        $title = trim((string) $video->title);
        if ($title !== '' && mb_strlen($title) <= 60) {
            $notes[] = 'The title "'.$title.'" is '.mb_strlen($title).' characters, inside the desktop cut.';
        }
        $thumb = trim((string) ($video->package['thumbnail'] ?? ''));
        $thumbCount = count($this->words->tokens($thumb));
        if ($thumb !== '' && $thumbCount <= 3 && $thumbCount > 0) {
            $shared = array_intersect($this->words->content($title), $this->words->content($thumb));
            if ($shared === []) {
                $notes[] = 'The thumbnail "'.$thumb.'" is '.$thumbCount.' words and does not repeat the title.';
            }
        }
        $script = (string) $video->script;
        if (str_contains($script, '[ON SCREEN:')) {
            $notes[] = 'The script marks what is on screen on the beats, starting with "'.$this->words->cut($this->opening($script), 80).'".';
        }

        return array_slice($notes, 0, 3);
    }

    private function opening(string $script): string
    {
        $plain = trim(preg_replace('/\[ON SCREEN:[^\]]*\]/', ' ', $script) ?? $script);
        $words = preg_split('/\s+/', $plain) ?: [];

        return implode(' ', array_slice($words, 0, 24));
    }
}
