<?php

namespace App\Services\YouTube;

use App\Models\AccountProfile;

/**
 * The local writer uses the same shape as the model path: five titles, two
 * scored hooks, on-screen marks, a named payoff, one ask, chapters, and a runtime.
 * A figure the account does not have is left out.
 */
class YouTubePackageWriter
{
    /** @var list<string> */
    private const BANNED = [
        'you do not need the expensive version',
        "you don't need the expensive version",
        'before we get started',
        'welcome back',
        'welcome to the channel',
        'smash the like',
    ];

    public function __construct(
        private readonly YouTubeWords $words,
        private readonly YouTubeTitleLinter $titles,
        private readonly YouTubeHookScorer $hooks,
        private readonly YouTubeChapterWriter $chapters,
    ) {}

    /**
     * @param  list<string>  $goals
     * @param  array{topic: string, query?: string, kind?: string, reason?: string, formula?: string|null}  $idea
     * @param  array{number?: int, title?: string, beat?: string, premise?: string}|null  $episode
     * @return array<string, mixed>
     */
    public function compose(AccountProfile $profile, array $goals, array $idea, string $format, ?string $lesson = null, ?array $episode = null): array
    {
        $niche = $profile->resolvedNiche();
        $audience = trim((string) $profile->audience) ?: 'the people this account is for';
        $description = trim((string) $profile->description) ?: $niche;
        $tone = $profile->primaryTone() ?: 'plain';
        $search = in_array('search', $goals, true);
        $changeOpening = is_string($lesson) && str_contains($lesson, 'first 30 seconds');
        $subject = trim((string) ($episode['title'] ?? $idea['topic'] ?? $niche));
        $names = array_values(array_filter([$niche, $audience]));

        $writtenTitles = $this->candidateTitles($niche, $audience, $subject);
        $scoredTitles = array_map(fn (string $title) => $this->titles->check($title, null, $names), $writtenTitles);
        usort($scoredTitles, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $winner = $scoredTitles[0]['title'] ?? $this->words->cut($niche, 60);

        $hookLines = $this->candidateHooks($niche, $audience);
        $scoredHooks = array_map(fn (string $line) => $this->hooks->score($line), $hookLines);
        usort($scoredHooks, fn (array $a, array $b) => $b['verdict'] <=> $a['verdict']);
        $keptHooks = array_slice($scoredHooks, 0, 2);
        $winning = $keptHooks[0];

        $onScreenTitles = [
            $changeOpening ? 'The result first' : 'The open question',
            'The first real step',
            'One step from here',
            'The promised part',
        ];
        $thumbnail = $this->thumbnail($winner, $onScreenTitles[0], $description, $names);
        $sections = $this->sections(
            $winning['hook'],
            $niche,
            $audience,
            $description,
            $tone,
            $onScreenTitles,
            $changeOpening,
            $lesson,
            $episode,
        );
        $chaptered = $this->chapters->fromSections(array_map(fn (array $section) => [
            'title' => $section['title'],
            'words' => $section['words'],
        ], $sections));

        $spokenParts = [];
        $onScreen = [];
        foreach ($sections as $index => $section) {
            $label = $chaptered['chapters'][$index]['label'] ?? '0:00';
            $spokenParts[] = '[ON SCREEN: '.$section['title'].']'."\n".$section['text'];
            $onScreen[] = ['text' => $section['title'], 'at' => $label];
        }
        $spoken = implode("\n\n", $spokenParts);
        $runtimeWords = $this->words->wordCount($spoken);
        $runtime = (int) round($runtimeWords / 150 * 60);

        $queries = $search ? [$niche, $audience, $description] : [];
        $descriptionText = $this->description(
            $search,
            $niche,
            $audience,
            $description,
            $chaptered['chapters'],
            $profile,
        );

        $briefWords = $thumbnail;
        $payoff = 'That is the shortcut this channel already claims. This video is delivering it.';
        $ask = 'Try it once, the way '.$audience.' already would.';

        return [
            'title' => $winner,
            'titles' => $scoredTitles,
            'titles_written' => count($scoredTitles),
            'hooks' => array_map(fn (array $hook) => [
                'text' => $hook['hook'],
                'score' => $hook['verdict'],
                'band' => $hook['band'],
                'formula' => $hook['formula'],
                'properties' => $hook['properties'],
            ], $keptHooks),
            'hooks_written' => count($scoredHooks),
            'hook' => $winning['hook'],
            'winning_formula' => $winning['formula'],
            'formula_why' => 'The winning hook uses '.$winning['formula'].'. It fits this idea without a figure the account does not have.',
            'spoken' => $spoken,
            'payoff' => $payoff,
            'ask' => $ask,
            'runtime_seconds' => $runtime,
            'runtime_words' => $runtimeWords,
            'description' => $descriptionText,
            'queries' => $queries,
            'search_facing' => $search,
            'tags' => $this->tags($description.' '.$niche),
            'chapters' => $chaptered['chapters'],
            'chapters_valid' => $chaptered['valid'],
            'thumbnail' => $briefWords,
            'thumbnail_brief' => [
                'expression' => 'Mid-thought, mouth slightly open, eyes on the thing the video delivers.',
                'framing' => 'Tight, from the chest up, the subject large enough to recognize at feed size.',
                'words' => $briefWords,
                'background' => 'A dark plain background with the subject in a light tone, so the three words stay readable when the thumbnail is the size of a phone feed.',
            ],
            'on_screen' => $onScreen,
            'shots' => $this->shots($format, $niche),
            'kind' => (string) ($idea['kind'] ?? 'piece'),
            'gate' => 'Ship it, or change it?',
        ];
    }

    /**
     * @param  list<string>  $queries
     */
    public function passesQueryTest(string $title, string $description, array $queries): bool
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\n/', $description) ?: [], fn (string $line) => trim($line) !== ''));
        $head = strtolower($title.' '.implode(' ', array_slice($lines, 0, 2)));
        foreach ($queries as $query) {
            foreach ($this->words->content($query) as $word) {
                if (! str_contains($head, $word)) {
                    return false;
                }
            }
        }

        return $queries !== [];
    }

    /**
     * Digits in the claims that were not in the source. Chapter timestamps are not claims.
     *
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    public function inventedDigits(array $package, string $allowed): array
    {
        $claims = [
            (string) ($package['title'] ?? ''),
            (string) ($package['thumbnail'] ?? ''),
            (string) ($package['spoken'] ?? ''),
            (string) ($package['payoff'] ?? ''),
            (string) ($package['ask'] ?? ''),
        ];
        foreach ($package['titles'] ?? [] as $title) {
            $claims[] = (string) ($title['title'] ?? '');
        }
        foreach ($package['hooks'] ?? [] as $hook) {
            $claims[] = (string) ($hook['text'] ?? '');
        }
        $description = (string) ($package['description'] ?? '');
        $prose = [];
        foreach (preg_split('/\r\n|\n/', $description) ?: [] as $line) {
            if (preg_match('/^\d+:\d{2}/', trim($line)) === 1) {
                continue;
            }
            $prose[] = $line;
        }
        $claims[] = implode("\n", $prose);

        $found = [];
        $allowedDigits = $this->words->digits($allowed);
        foreach ($claims as $claim) {
            foreach ($this->words->digits($claim) as $digit) {
                if (! in_array($digit, $allowedDigits, true)) {
                    $found[$digit] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** @return list<string> */
    private function candidateTitles(string $niche, string $audience, string $subject): array
    {
        $raw = [
            "{$niche} for {$audience}",
            "How {$audience} handle {$niche}",
            "{$niche} when {$audience} start",
            "What {$niche} asks of {$audience}",
            "{$subject} inside {$niche}",
        ];
        $titles = [];
        foreach ($raw as $title) {
            $fit = $this->fitTitle($title);
            if ($fit !== '' && ! in_array($fit, $titles, true)) {
                $titles[] = $fit;
            }
        }
        foreach (['in plain words', 'as already said', 'for this account', 'without a new claim', 'in this voice'] as $extra) {
            if (count($titles) >= 5) {
                break;
            }
            $fit = $this->fitTitle($niche.' '.$extra);
            if ($fit !== '' && ! in_array($fit, $titles, true)) {
                $titles[] = $fit;
            }
        }

        return array_slice($titles, 0, 5);
    }

    private function fitTitle(string $title): string
    {
        $title = $this->words->cut(trim($title), YouTubeTitleLinter::DESKTOP);
        if (mb_strlen($title) > YouTubeTitleLinter::MOBILE || $title === '') {
            return $title;
        }
        $withDate = $title.' this week';
        if (mb_strlen($withDate) <= YouTubeTitleLinter::DESKTOP) {
            return $withDate;
        }

        return $title;
    }

    /** @return list<string> */
    private function candidateHooks(string $niche, string $audience): array
    {
        return [
            "You are probably doing {$niche} from the wrong end before the plate exists.",
            "How do you finish {$niche} for {$audience}?",
            "Do not start before you name the {$niche} plate.",
            "I am going to show you the shortcut, on screen, for {$audience}.",
            "You already have the shortcut, but nobody tells {$audience} where {$niche} finishes.",
        ];
    }

    /**
     * @param  list<string>  $onScreenTitles
     * @param  array{beat?: string, premise?: string}|null  $episode
     * @return list<array{title: string, text: string, words: int}>
     */
    private function sections(
        string $hook,
        string $niche,
        string $audience,
        string $description,
        string $tone,
        array $onScreenTitles,
        bool $changeOpening,
        ?string $lesson,
        ?array $episode,
    ): array {
        $open = $hook.' The part still open is how '.$audience.' get there from what this account already does. The payoff is already in the description, and this video shows it.';
        if ($changeOpening) {
            $open = 'Start on the result. '.$open;
        }
        $turn = 'This video shows '.$description.' It starts now, in a '.$tone.' voice, with '.$niche.' for '.$audience.'. No setup before the meal. The shortcut that actually finishes is the thing being done from this moment.';
        $body = $description.' '.$audience.' can use that and nothing past it.';
        if (is_array($episode) && trim((string) ($episode['beat'] ?? '')) !== '') {
            $body .= ' This episode stays on '.$episode['beat'].' The premise stays: '.($episode['premise'] ?? $description);
        }
        if (is_string($lesson) && str_contains($lesson, 'Cut the middle')) {
            $body .= ' The middle of this one stays short. That is a cut, not a new opening.';
        }
        $close = 'That is the shortcut this channel already claims. This video is delivering it. Try it once, the way '.$audience.' already would.';
        $pad = $description.' The account does not add a result it has not earned.';

        $texts = [$open, $turn, $body, $close];
        $minimums = [30, 70, 40, 30];
        $sections = [];
        foreach ($texts as $index => $text) {
            $text = $this->ensureWords($text, $minimums[$index], $pad);
            $sections[] = [
                'title' => $onScreenTitles[$index],
                'text' => $text,
                'words' => $this->words->wordCount($text),
            ];
        }

        return $sections;
    }

    private function ensureWords(string $text, int $min, string $extra): string
    {
        $guard = 0;
        while ($this->words->wordCount($text) < $min && $guard < 8) {
            $text = rtrim($text).' '.$extra;
            $guard++;
        }

        return trim($text);
    }

    /**
     * @param  list<string>  $names
     */
    private function thumbnail(string $title, string $firstOnScreen, string $description, array $names): string
    {
        $options = ['Hold the contrast', 'See it plainly', 'Read this small'];
        if (preg_match('/\b(cook|meal|meals|kitchen)\b/i', $description) === 1) {
            array_unshift($options, 'The finished plate');
        }
        foreach ($options as $option) {
            if ($this->titles->episodeLabel($option) || strcasecmp($option, $firstOnScreen) === 0) {
                continue;
            }
            if (count($this->words->tokens($option)) > 3) {
                continue;
            }
            $checked = $this->titles->check($title, $option, $names);
            $blocked = false;
            foreach ($checked['issues'] as $issue) {
                if (in_array($issue[0], ['duplicate', 'thumb-length'], true)) {
                    $blocked = true;
                }
            }
            if (! $blocked) {
                return $option;
            }
        }

        return 'See it plainly';
    }

    /**
     * @param  list<array{start: int, label: string, title: string, seconds: int}>  $chapters
     */
    private function description(
        bool $search,
        string $niche,
        string $audience,
        string $account,
        array $chapters,
        AccountProfile $profile,
    ): string {
        $link = null;
        if (preg_match('#https?://\S+#', (string) $profile->description, $match) === 1) {
            $link = $match[0];
        }
        $line1 = $search
            ? $niche.' for '.$audience.'.'
            : 'For the people who already watch.';
        $line2 = $search
            ? $account
            : $account.' The work on this one is the title and the thumbnail.';

        $parts = [$line1, $line2, ''];
        if ($link !== null) {
            $parts[] = $link;
            $parts[] = '';
        }
        $parts[] = $this->chapters->block($chapters);
        $parts[] = '';
        $parts[] = 'Covered: '.$account;
        $parts[] = 'For: '.$audience.'.';
        $parts[] = 'Assumes: '.$audience.' already want what the description says, and nothing beyond it.';

        return implode("\n", $parts);
    }

    /** @return list<string> */
    private function tags(string $source): array
    {
        $tags = [];
        $lower = strtolower($source);
        if (str_contains($lower, 'weeknight')) {
            $tags[] = 'weeknight';
            $tags[] = 'week night';
        }
        if (preg_match_all('/\b[A-Z]{2,5}\b/', $source, $matches) === 1 || isset($matches[0])) {
            foreach ($matches[0] ?? [] as $token) {
                $tags[] = $token;
            }
        }

        return array_slice(array_values(array_unique($tags)), 0, 15);
    }

    /** @return list<array{prompt: string, seconds: int, kind: string}> */
    private function shots(string $format, string $niche): array
    {
        if ($format === 'text_on_screen') {
            return [];
        }
        $scene = "Plain scene about {$niche}. No logos, no readable text, no extra gear.";

        return [
            ['prompt' => $scene.' Open on the result.', 'seconds' => 8, 'kind' => 'clip'],
            ['prompt' => $scene.' The one step the description already names.', 'seconds' => 8, 'kind' => 'clip'],
        ];
    }
}
