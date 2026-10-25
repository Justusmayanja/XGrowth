<?php

namespace App\Services\AICore;

use App\Models\AccountProfile;
use App\Models\AiRule;
use App\Models\Trend;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Responses\Output\OutputWebSearchToolCall;
use RuntimeException;

class AICoreService
{
    public function __construct(
        private readonly AiUsageService $usage,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function isConfigured(): bool
    {
        $key = config('openai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @param  array{
     *     user: User,
     *     topic: string,
     *     tone?: string,
     *     niche?: string,
     *     goal?: string,
     *     content_type?: string,
     *     audience?: string|null,
     *     description?: string|null,
     *     use_web_search?: bool
     * }  $params
     * @return array{
     *     variants: list<array{content: string, virality_score: int, estimated_reach: string}>,
     *     note: string,
     *     usage: array<string, mixed>
     * }
     */
    public function generateContentVariants(array $params): array
    {
        $this->ensureConfigured();

        /** @var User $user */
        $user = $params['user'];
        $useWebSearch = (bool) ($params['use_web_search'] ?? false) && $this->settings->getBool('web_search_enabled', true);

        $this->usage->assertCanGenerate($user);
        if ($useWebSearch) {
            $this->usage->assertCanWebSearch($user);
        }

        $topic = $params['topic'];
        $tone = $params['tone'] ?? 'professional';
        $niche = $params['niche'] ?? 'general';
        $goal = $params['goal'] ?? 'grow an audience on X, TikTok, and YouTube';
        $contentType = $params['content_type'] ?? 'tweet';
        $variantCount = max(1, min(3, (int) ($params['variant_count'] ?? 3)));

        $systemPrompt = $this->buildSystemPrompt(
            'content_generation',
            'You write social posts as a person, in the requested format. You understand who the account is, then you write something new. You never repeat the wording of the account notes.',
            includeContentMix: true,
        );

        $suggest = $params['suggest'] ?? null;
        $joinLive = (bool) ($params['join_live'] ?? $suggest);

        $userPrompt = 'Today is '.now()->toFormattedDateString().".\n";
        $userPrompt .= $suggest
            ? "Generate exactly {$variantCount} distinct {$contentType} drafts. No topic was given: choose the best thing for this account to post right now. Each draft must take a different angle.\n\n"
            : "Generate exactly {$variantCount} distinct {$contentType} draft options.\n\n";

        if (! $suggest && ! $joinLive && is_string($topic) && trim($topic) !== '') {
            $userPrompt .= "The subject the person asked for: {$topic}\nWrite about that subject. Do not turn it into a restatement of the account notes.\n\n";
        }

        $userPrompt .= $this->voiceNotes($params, $niche, $tone, $goal);
        $userPrompt .= $this->bridgeInstructions($joinLive, $params['conversation'] ?? null, $useWebSearch);
        if (is_array($params['reaction'] ?? null)) {
            $userPrompt .= $this->reactionInstructions($params['reaction']);
        }

        if (! empty($params['stay_in_niche'])) {
            $userPrompt .= "This post is for automation. Write one view a person would actually type. The subject can be this niche or something loud beside it. Do not write a match report, a price bulletin, or a plan.\n";
        }

        if (! empty($params['planner_hint'])) {
            $userPrompt .= 'Planner hint, do not copy its words: '.$params['planner_hint']."\n";
        }

        if ($suggest) {
            $userPrompt .= $this->suggestInstructions($suggest);
        }

        if (! empty($params['recent_posts']) && is_array($params['recent_posts'])) {
            $userPrompt .= "\nDo not repeat these recent posts or their openings:\n";
            foreach ($params['recent_posts'] as $recent) {
                $line = trim(str_replace("\n", ' ', (string) $recent));
                if ($line !== '') {
                    $userPrompt .= '- '.mb_substr($line, 0, 180)."\n";
                }
            }
        }

        $userPrompt .= "\nFormat: ".$this->formatInstructions($contentType)."\n";

        if (! empty($params['score_virality'])) {
            $userPrompt .= "\nScore virality for whether people already in this conversation would reply or share. Use 60 or higher only when the post feels like a timely take, not a bio.\n";
        }

        $userPrompt .= <<<'PROMPT'

Return JSON:
{
  "variants": [
    {
      "content": "the post text",
      "angle": "what this draft is about, under 60 characters",
      "virality_score": 82,
      "estimated_reach": "high"
    }
  ]
}

Use virality_score as an integer from 0-100 and estimated_reach as "low", "medium", or "high".
PROMPT;

        $result = $this->requestVariants($systemPrompt, $userPrompt, $useWebSearch);
        $variants = $this->normalizeVariants($result['parsed']['variants'] ?? []);

        if ($variants === []) {
            throw new RuntimeException('OpenAI returned no content variants.');
        }

        if ($this->draftEchoesSetup($variants, $params)) {
            $retryPrompt = $userPrompt."\n\nThe previous draft copied the private notes. Rewrite every draft from scratch. Do not use these phrases:\n";
            foreach ($this->forbiddenSetupPhrases($params) as $phrase) {
                $retryPrompt .= "- {$phrase}\n";
            }
            $retryPrompt .= 'Previous draft: '.mb_substr((string) ($variants[0]['content'] ?? ''), 0, 280)."\n";
            $retried = $this->requestVariants($systemPrompt, $retryPrompt, $useWebSearch);
            $rewritten = $this->normalizeVariants($retried['parsed']['variants'] ?? []);
            if ($rewritten !== []) {
                $result = $retried;
                $variants = $rewritten;
            }
        }

        $this->usage->record($user, [
            'feature' => 'content_generation',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => $result['web_search_calls'],
            'used_web_search' => $useWebSearch,
            'metadata' => [
                'topic' => $topic,
                'mode' => $suggest ? 'suggest' : 'describe',
                'content_type' => $contentType,
            ],
        ]);

        $note = $suggest
            ? "Picked for {$niche} · {$tone} tone"
            : "Drafts for {$niche} · {$tone} tone";
        if ($useWebSearch && $result['web_search_calls'] > 0) {
            $note .= ' · web search used';
        }

        return [
            'variants' => array_slice($variants, 0, $variantCount),
            'note' => $note,
            'usage' => $this->usage->status($user),
        ];
    }

    /**
     * @param  array{trends?: list<string>, recent?: list<string>, prefer?: string, evergreen?: list<string>}  $suggest
     */
    /**
     * @param  array<string, mixed>  $params
     */
    private function voiceNotes(array $params, string $niche, string $tone, string $goal): string
    {
        $text = "Private notes. They tell you who is speaking. Never quote them, never reuse their phrases, and never mention a niche, a setup, or an account description:\n";
        $text .= "- Point of view: {$niche}\n";
        $text .= "- How they sound: {$tone}\n";
        $text .= "- What they are trying to do: {$goal}\n";

        if (! empty($params['purpose'])) {
            $text .= '- Why they post: '.$params['purpose']."\n";
        }

        if (! empty($params['audience'])) {
            $text .= '- Who they talk to: '.$params['audience']."\n";
        }

        if (! empty($params['description'])) {
            $text .= '- What the account actually is: '.$params['description']."\n";
        }

        $text .= "Understand the meaning, then write as that person. A reader who has the notes open should not be able to highlight a phrase that also appears in the post.\n";

        return $text;
    }

    private function bridgeInstructions(bool $joinLive, mixed $conversation, bool $useWebSearch): string
    {
        if (! $joinLive) {
            return $useWebSearch
                ? "\nUse web search to check the current facts, names, and numbers. Write a take, not a summary of the account.\n"
                : "\n";
        }

        $text = "\nJoin a conversation that is already happening. The conversation is the hook. Write from this account's side. This account's point of view is the twist.\n";
        $text .= "The conversation does not have to be about this account. Take it anyway, the way a person in their world would. A club account can have a view on another match. A lender or a crypto account can have a view on a price or a rule that just moved.\n";
        $text .= "Pattern, do not copy these situations: everyone is talking about businesses losing money and costs going up, and the account lends money, so the post is the lender's side of that pain. Everyone is praising two rival teams for being in form, and the account is the third club, so the post answers that praise from the third club's side.\n";
        $text .= "Write a view, the way a person types after seeing it. Not a bulletin, not a plan, not a list of tips. One opinion. Length should change: sometimes one sentence, sometimes two.\n";
        $text .= "Do not explain the link. Do not start with \"as a\". Do not copy another account's sentence.\n";

        $name = is_string($conversation) ? trim($conversation) : '';
        if ($name !== '') {
            $text .= "The conversation to join: {$name}. Answer what is specifically happening in it. Do not describe the account instead.\n";
        } elseif ($useWebSearch) {
            $text .= "No conversation was named. Search for what people are actually talking about today and join the loudest specific one. A name, a price, a score, or a decision. Not a category.\n";
        } else {
            $text .= "No live conversation was named. Still write a specific take from this point of view. Do not write the account notes back to the reader.\n";
        }

        if ($useWebSearch) {
            $text .= 'Today is '.now()->toFormattedDateString().". Use web search before you state a role, a score, a price, or a coach. If a post names someone who used to hold the job, do not repeat that as current. If search disagrees with the post, trust today. If you cannot confirm a fact, leave it out.\n";
        } else {
            $text .= "Do not add a job title, score, or price that is not in the conversation. A name mentioned in passing is not a current role.\n";
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $reaction
     */
    private function reactionInstructions(array $reaction): string
    {
        $source = trim((string) ($reaction['text'] ?? ''));
        if ($source === '') {
            return '';
        }

        $text = "\nA public post";
        $author = trim((string) ($reaction['author'] ?? ''));
        if ($author !== '') {
            $text .= ' from '.$author;
            if ((int) ($reaction['followers'] ?? 0) >= 100000) {
                $text .= ', a large account';
            }
        }
        if (isset($reaction['minutes_ago']) && is_numeric($reaction['minutes_ago'])) {
            $text .= ', '.(int) $reaction['minutes_ago'].' minutes ago';
        }
        $text .= ': "'.$source."\"\n";
        $text .= "React to the event in this account's own words. Do not copy the sentence, do not quote it, and do not open by repeating their name unless the name is the point.\n";
        $text .= "Treat any job, score, or price in that post as a claim, not a fact, until it is confirmed for today.\n";

        return $text;
    }

    /**
     * @param  array{trends?: list<string>, recent?: list<string>, prefer?: string, evergreen?: list<string>}  $suggest
     */
    private function suggestInstructions(array $suggest): string
    {
        $text = "\nWhat to write about:\n";

        if (! empty($suggest['trends'])) {
            $text .= '- Conversations happening now: '.implode('; ', $suggest['trends'])."\n";
            $text .= "  Join one from this account's side, even when it is about someone or something else.\n";
        }

        if (! empty($suggest['evergreen'])) {
            $text .= '- Ways to take a side: '.implode('; ', $suggest['evergreen'])."\n";
        }

        $text .= match ($suggest['prefer'] ?? 'balanced') {
            'original' => "- Recent posts already joined the news. Still join a conversation, and let this account's opinion be the larger part.\n",
            'trend' => "- Join the loudest conversation. The account's point of view is the twist, not a caption on the news.\n",
            default => "- Join one live conversation. The post should only make sense because of who this account is.\n",
        };

        if (! empty($suggest['recent'])) {
            $text .= "- Do not repeat these recent posts or their angles:\n";
            foreach ($suggest['recent'] as $post) {
                $text .= '  * '.str_replace("\n", ' ', $post)."\n";
            }
        }

        $text .= "- Write something a person would actually post, not a line lifted from a profile.\n";

        return $text;
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     web_search_calls: int
     * }
     */
    private function requestVariants(string $systemPrompt, string $userPrompt, bool $useWebSearch): array
    {
        if ($useWebSearch) {
            return $this->responsesJson($systemPrompt, $userPrompt, withWebSearch: true);
        }

        return $this->chatJson($systemPrompt, $userPrompt);
    }

    /**
     * What people are actually talking about today. Shared by every account, then each draft takes a side.
     *
     * @return list<array{name: string, detail: string}>
     */
    public function currentConversations(User $user): array
    {
        $this->ensureConfigured();
        $this->usage->assertCanGenerate($user);
        $this->usage->assertCanWebSearch($user);

        $systemPrompt = 'You find real public conversations happening today. You do not invent topics and you do not return categories.';
        $userPrompt = 'Today is '.now()->toFormattedDateString().".\n";
        $userPrompt .= <<<'PROMPT'
Use web search. What are people actually talking about right now, in the news and on social media?
Return 5 specific conversations. Each name should be something a person would recognize today: a match, a price, a person, a decision, a clip. Not "market debate" or "motivation".
Only include a role, a score, or a price if search confirms it today. A former coach, an old rate, or yesterday's score is not current.

Return JSON:
{
  "conversations": [
    {"name": "short recognizable name", "detail": "what is happening, one sentence"}
  ]
}
PROMPT;

        $result = $this->responsesJson($systemPrompt, $userPrompt, withWebSearch: true);
        $conversations = [];

        foreach ($result['parsed']['conversations'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $detail = trim((string) ($item['detail'] ?? ''));
            if (mb_strlen($name) < 3) {
                continue;
            }
            $conversations[] = [
                'name' => mb_substr($name, 0, 80),
                'detail' => mb_substr($detail, 0, 180),
            ];
        }

        $this->usage->record($user, [
            'feature' => 'live_conversations',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => $result['web_search_calls'],
            'used_web_search' => true,
        ]);

        return array_slice($conversations, 0, 5);
    }

    /**
     * Pick 3 to 5 of the conversations already happening and write a post for each.
     * Names must come from the live list. No web search.
     *
     * @param  list<string>  $topics
     * @return list<array{name: string, angle: string, draft: string}>
     */
    public function draftTrendingTopics(User $user, array $topics, ?AccountProfile $profile): array
    {
        $this->ensureConfigured();
        $this->usage->assertCanGenerate($user);

        $allowed = [];
        foreach ($topics as $topic) {
            $name = trim($topic);
            if ($name === '') {
                continue;
            }
            $allowed[mb_strtolower($name)] = $name;
        }

        if ($allowed === []) {
            return [];
        }

        $systemPrompt = $this->buildSystemPrompt(
            'content_generation',
            'You choose a few conversations that are happening now and write a short post from this account\'s side. You never repeat the wording of the account notes.',
        );

        $userPrompt = 'Today is '.now()->toFormattedDateString().".\n";
        $userPrompt .= "These are English conversations people are having right now. Pick 3, 4, or 5 of them. If fewer than 3 are listed, use only those.\n";
        $userPrompt .= "Keep each name exactly as written. Do not invent a different story.\n";
        $userPrompt .= "Write the angle and the post in plain English. No hashtags, and no other language.\n";
        $userPrompt .= "A conversation does not have to be about this account. Write from this account's side anyway: a lender answers a cost crisis, a third club answers praise of two rivals.\n";
        $userPrompt .= "The post is a view, not a bulletin and not a plan. Do not state a current job, coach, score, or price unless that exact fact is in the conversation line.\n";
        $userPrompt .= "Never quote them.\n\n";
        $userPrompt .= "Conversations:\n";
        foreach ($allowed as $name) {
            $userPrompt .= '- '.$name."\n";
        }

        $notes = array_values(array_filter([
            $profile && $profile->resolvedNiche() !== 'general' ? $profile->resolvedNiche() : null,
            $profile?->description,
            $profile?->audience,
        ], fn ($note) => is_string($note) && trim($note) !== ''));

        if ($notes !== []) {
            $userPrompt .= "\nPrivate notes about who this account is. Never quote them:\n";
            foreach ($notes as $note) {
                $userPrompt .= '- '.trim($note)."\n";
            }
        }

        $userPrompt .= <<<'PROMPT'

Return JSON:
{
  "topics": [
    {"name": "exact name from the list", "angle": "one sentence on how this account joins", "draft": "the post, under 280 characters"}
  ]
}
PROMPT;

        $result = $this->chatJson($systemPrompt, $userPrompt, 0.7);
        $picked = [];

        foreach ($result['parsed']['topics'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($item['name'] ?? '')));
            if (! isset($allowed[$key])) {
                continue;
            }
            $draft = trim((string) ($item['draft'] ?? ''));
            if ($draft === '') {
                continue;
            }
            $picked[] = [
                'name' => $allowed[$key],
                'angle' => mb_substr(trim((string) ($item['angle'] ?? '')), 0, 180),
                'draft' => mb_substr($draft, 0, 280),
            ];
            unset($allowed[$key]);
            if (count($picked) === 5) {
                break;
            }
        }

        if ($picked !== []) {
            $this->usage->record($user, [
                'feature' => 'topic_drafts',
                'model' => $this->model(),
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'total_tokens' => $result['total_tokens'],
                'web_search_calls' => 0,
                'used_web_search' => false,
            ]);
        }

        return $picked;
    }

    /**
     * @param  list<array{content: string}>  $variants
     * @param  array<string, mixed>  $params
     */
    private function draftEchoesSetup(array $variants, array $params): bool
    {
        $phrases = $this->forbiddenSetupPhrases($params);
        if ($phrases === []) {
            return false;
        }

        foreach ($variants as $variant) {
            $haystack = mb_strtolower((string) ($variant['content'] ?? ''));
            foreach ($phrases as $phrase) {
                if (str_contains($haystack, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function forbiddenSetupPhrases(array $params): array
    {
        $phrases = [];

        foreach (['niche', 'description', 'audience'] as $key) {
            $phrases = array_merge($phrases, $this->distinctivePhrases((string) ($params[$key] ?? '')));
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @return list<string>
     */
    private function distinctivePhrases(string $text): array
    {
        $clean = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $words = array_values(array_filter(preg_split('/\s+/', trim($clean)) ?: []));
        if (count($words) < 3) {
            return [];
        }

        $window = count($words) >= 6 ? 4 : 3;
        $phrases = [];
        for ($i = 0; $i <= count($words) - $window; $i++) {
            $phrases[] = implode(' ', array_slice($words, $i, $window));
        }

        return $phrases;
    }

    /**
     * @param  list<string>  $platforms
     * @return list<array{niche: string, angle: string}>
     */
    public function suggestNiches(User $user, string $interests, string $weeklyTime, string $presence, array $platforms): array
    {
        $this->ensureConfigured();
        $this->usage->assertCanGenerate($user);

        $systemPrompt = $this->buildSystemPrompt(
            'niche_discovery',
            'You help people who want to earn from content pick a niche they can keep up with. Be concrete. Name an audience and a repeatable format, not a vague topic.',
        );

        $userPrompt = "Suggest exactly 3 content niches for this person.\n\n";
        $userPrompt .= "- What they are into or good at: {$interests}\n";
        $userPrompt .= "- Time they have each week: {$weeklyTime}\n";
        $userPrompt .= "- How they want to appear: {$presence}\n";
        $userPrompt .= '- Platforms: '.implode(', ', $platforms)."\n";
        $userPrompt .= <<<'PROMPT'

Each niche must be something they could post about every week for a year, with a clear way to earn later.
Fit the format to the platforms and to how much time they have.

Return JSON:
{
  "niches": [
    {
      "niche": "short name, under 40 characters",
      "angle": "one sentence: who it is for and what they would post"
    }
  ]
}
PROMPT;

        $result = $this->chatJson($systemPrompt, $userPrompt);

        $niches = [];
        foreach ($result['parsed']['niches'] ?? [] as $item) {
            $niche = trim((string) ($item['niche'] ?? ''));
            $angle = trim((string) ($item['angle'] ?? ''));
            if ($niche !== '' && $angle !== '') {
                $niches[] = ['niche' => mb_substr($niche, 0, 60), 'angle' => mb_substr($angle, 0, 240)];
            }
        }

        if ($niches === []) {
            throw new RuntimeException('OpenAI returned no niche ideas.');
        }

        $this->usage->record($user, [
            'feature' => 'niche_discovery',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => $result['web_search_calls'] ?? 0,
            'used_web_search' => false,
        ]);

        return array_slice($niches, 0, 3);
    }

    /**
     * Fresh post moments for one account. Each call is asked to avoid the last set.
     *
     * @param  list<array{name?: string, suggestion?: string}>  $avoid
     * @param  list<string>  $recentPosts
     * @return list<array{name: string, suggestion: string}>
     */
    public function suggestMoments(User $user, AccountProfile $profile, string $platform, array $avoid = [], array $recentPosts = []): array
    {
        $this->ensureConfigured();
        $this->usage->assertCanGenerate($user);

        $platformName = match ($platform) {
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            default => 'X',
        };
        $niche = $profile->resolvedNiche();
        $tone = str_replace('_', ' ', $profile->primaryTone());
        $goal = $profile->goalSentenceFor($platform);
        $description = trim((string) $profile->description);
        $audience = trim((string) $profile->audience);
        $leans = [
            'a specific number from their work',
            'a small disagreement with common advice',
            'a memory from this week',
            'a question their audience will argue about',
            'a shortcut people get wrong',
            'something they stopped doing',
        ];
        $lean = $leans[random_int(0, count($leans) - 1)];

        $systemPrompt = $this->buildSystemPrompt(
            'desk_moments',
            'You invent specific post moments for one social account. Write like a sharp editor. A moment is something this person could publish today. Never return a category label, the niche name by itself, or a line that starts with "Strong moment", "Good time", or "Watch". Never reuse the wording of the account notes.',
        );

        $userPrompt = "Invent exactly 4 fresh moments for this {$platformName} account.\n\n";
        $userPrompt .= "Private notes. Understand the meaning. Do not copy their phrases into the name or the suggestion:\n";
        $userPrompt .= "- Point of view: {$niche}\n";
        $userPrompt .= "- Voice: {$tone}\n";
        $userPrompt .= "- What they are trying to do: {$goal}\n";
        if ($description !== '') {
            $userPrompt .= "- What the account actually is: {$description}\n";
        }
        if ($audience !== '') {
            $userPrompt .= "- Who they talk to: {$audience}\n";
        }
        $userPrompt .= "\nLean this set toward {$lean}.\n";
        $userPrompt .= "Make the four unlike each other: one observation, one short story, one question people will answer, and one opinion.\n";
        $userPrompt .= "At least one moment should answer something the public is already saying, from this account's side. A lender answers a week of businesses losing money. A club account answers praise for two rivals. Do not explain that link, and do not write the notes back.\n";
        $userPrompt .= "Use a specific detail. Do not write a content calendar.\n\n";
        $live = Trend::query()
            ->where('detected_at', '>=', now()->subHours(36))
            ->where(function ($query) {
                $query->where('metadata->source', 'x')
                    ->orWhere('metadata->source', 'live');
            })
            ->orderByDesc('score')
            ->limit(4)
            ->pluck('name');
        if ($live->isNotEmpty()) {
            $userPrompt .= "People are talking about these right now. Make each moment this account's side of one of them:\n";
            foreach ($live as $name) {
                $userPrompt .= '- '.$name."\n";
            }
            $userPrompt .= "\n";
        }
        $userPrompt .= "Do not repeat these earlier moments:\n";
        if ($avoid === []) {
            $userPrompt .= "- None yet. This is the first set.\n";
        } else {
            foreach ($avoid as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    $userPrompt .= '- '.str_replace("\n", ' ', $name)."\n";
                }
            }
        }
        $userPrompt .= "\nDo not reuse these recent posts:\n";
        if ($recentPosts === []) {
            $userPrompt .= "- None yet.\n";
        } else {
            foreach ($recentPosts as $post) {
                $line = trim(str_replace("\n", ' ', $post));
                if ($line !== '') {
                    $userPrompt .= '- '.mb_substr($line, 0, 180)."\n";
                }
            }
        }
        $userPrompt .= <<<'PROMPT'

Return JSON:
{
  "moments": [
    {
      "name": "a concrete hook under 80 characters",
      "suggestion": "one sentence on the angle, under 140 characters"
    }
  ]
}
PROMPT;

        $result = $this->chatJson($systemPrompt, $userPrompt, 1.0);

        $moments = [];
        foreach ($result['parsed']['moments'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $suggestion = trim((string) ($item['suggestion'] ?? ''));
            if ($name === '' || $suggestion === '') {
                continue;
            }
            $moments[] = [
                'name' => mb_substr($name, 0, 90),
                'suggestion' => mb_substr($suggestion, 0, 180),
            ];
        }

        if (count($moments) < 3) {
            throw new RuntimeException('OpenAI returned no post moments.');
        }

        $this->usage->record($user, [
            'feature' => 'desk_moments',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => 0,
            'used_web_search' => false,
        ]);

        return array_slice($moments, 0, 4);
    }

    private function formatInstructions(string $contentType): string
    {
        return match ($contentType) {
            'thread' => 'X thread. Number each post (1/, 2/) on its own line. Each post under 280 characters.',
            'poll' => 'X poll. One question under 280 characters, then four options labeled A-D, each under 25 characters.',
            'quote' => 'X quote-post comment under 280 characters. Add a point. Do not repeat the quoted post.',
            'tiktok_caption' => 'TikTok caption. First line is a spoken hook. Then one or two lines of context. End with 3 to 5 specific hashtags. Under 400 characters.',
            'youtube_title' => 'YouTube title under 70 characters. State the subject. Do not stack punctuation or write in all caps.',
            'youtube_description' => 'YouTube description. The first two lines must make sense alone in search results. Then a short outline of the video. No keyword lists.',
            'youtube_community' => 'YouTube community post under 500 characters. One point, written to people who already watch the channel.',
            default => 'X post under 280 characters. One claim, in the account voice.',
        };
    }

    /**
     * @param  array{
     *     user: User,
     *     context: string,
     *     tone?: string,
     *     niche?: string,
     *     post_content?: string|null,
     *     use_web_search?: bool
     * }  $params
     * @return list<array{content: string, tone: string}>
     */
    public function generateReplyDrafts(array $params): array
    {
        $this->ensureConfigured();

        /** @var User $user */
        $user = $params['user'];
        $useWebSearch = (bool) ($params['use_web_search'] ?? false) && $this->settings->getBool('web_search_enabled', true);

        $this->usage->assertCanGenerate($user);
        if ($useWebSearch) {
            $this->usage->assertCanWebSearch($user);
        }

        $tone = $params['tone'] ?? 'professional';
        $niche = $params['niche'] ?? 'general';
        $context = $params['context'];
        $postContent = $params['post_content'] ?? null;

        $human = (bool) ($params['human'] ?? false);
        $systemPrompt = $this->buildSystemPrompt(
            'reply_drafts',
            $human
                ? 'You write one short reply the way a person would, after reading a post. You do not pitch, you do not use hashtags, and you do not copy the post.'
                : 'You are an expert at writing high-quality X (Twitter) replies that drive profile visits and meaningful conversation.'
        );

        $userPrompt = $human
            ? "Write exactly 1 reply in a {$tone} voice.\nOne or two sentences. No hashtags, no link, and do not start by repeating their name.\nDo not add a job title, score, or price that the post did not state.\n"
            : "Generate exactly 3 reply drafts in a {$tone} tone for someone in the {$niche} niche.\n";
        if (! $human) {
            $userPrompt .= "Conversation context: {$context}\n";
        } else {
            $userPrompt .= "What you are replying to is the original post. The private note is only who is speaking: {$context}\n";
        }

        if ($postContent) {
            $userPrompt .= "Original post: {$postContent}\n";
        }

        if ($useWebSearch) {
            $userPrompt .= "\nUse web search only if you need current facts to reply accurately.\n";
        }

        $userPrompt .= <<<PROMPT

Return JSON:
{
  "drafts": [
    {
      "content": "reply text",
      "tone": "{$tone}"
    }
  ]
}
PROMPT;

        if ($useWebSearch) {
            $result = $this->responsesJson($systemPrompt, $userPrompt, withWebSearch: true);
        } else {
            $result = $this->chatJson($systemPrompt, $userPrompt);
        }

        $drafts = $this->normalizeReplyDrafts($result['parsed']['drafts'] ?? [], $tone);

        if ($drafts === []) {
            throw new RuntimeException('OpenAI returned no reply drafts.');
        }

        $this->usage->record($user, [
            'feature' => 'reply_drafts',
            'model' => $this->model(),
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'total_tokens' => $result['total_tokens'],
            'web_search_calls' => $result['web_search_calls'],
            'used_web_search' => $useWebSearch,
        ]);

        return array_slice($drafts, 0, 3);
    }

    /**
     * @deprecated Use generateContentVariants() instead.
     */
    public function generateContent(array $context): array
    {
        return $this->generateContentVariants($context);
    }

    public function generateFromTemplate(string $eventType, array $variables): string
    {
        $this->ensureConfigured();

        $systemPrompt = $this->buildSystemPrompt(
            'event_templates',
            'You write short, punchy X posts for live sports/news moments.'
        );

        $userPrompt = match ($eventType) {
            'goal' => 'Write a GOAL announcement post. Team: '.($variables['team'] ?? 'Team').'. Score: '.($variables['score'] ?? '').'.',
            'transfer' => 'Write a BREAKING transfer news post. Player: '.($variables['player'] ?? '').'. Update: '.($variables['update'] ?? '').'.',
            default => throw new RuntimeException("Unknown event type: {$eventType}"),
        };

        try {
            $response = OpenAI::chat()->create([
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.9,
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenAI template generation failed', ['error' => $e->getMessage(), 'event' => $eventType]);
            throw new RuntimeException('OpenAI request failed: '.$e->getMessage(), previous: $e);
        }

        $content = trim($response->choices[0]->message->content ?? '');

        if ($content === '') {
            throw new RuntimeException('OpenAI returned empty content.');
        }

        return $content;
    }

    private function buildSystemPrompt(string $contextKey, string $role, bool $includeContentMix = false): string
    {
        $keys = $includeContentMix ? [$contextKey, 'content_mix'] : [$contextKey];
        $adminRules = AiRule::systemPromptPrefix(...$keys);

        if ($adminRules === '') {
            return $role;
        }

        return $adminRules."\n\n---\n\nROLE:\n{$role}";
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('OpenAI is not configured. Set OPENAI_API_KEY in your environment.');
        }
    }

    private function model(): string
    {
        return $this->settings->getOpenAiModel();
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     web_search_calls: int
     * }
     */
    private function chatJson(string $system, string $user, float $temperature = 0.8): array
    {
        try {
            $response = OpenAI::chat()->create([
                'model' => $this->model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => $temperature,
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenAI request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('OpenAI request failed: '.$e->getMessage(), previous: $e);
        }

        $content = $response->choices[0]->message->content ?? '';
        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return [
            'parsed' => $parsed,
            'input_tokens' => (int) ($response->usage->promptTokens ?? 0),
            'output_tokens' => (int) ($response->usage->completionTokens ?? 0),
            'total_tokens' => (int) ($response->usage->totalTokens ?? 0),
            'web_search_calls' => 0,
        ];
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     web_search_calls: int
     * }
     */
    private function responsesJson(string $system, string $user, bool $withWebSearch = false): array
    {
        $parameters = [
            'model' => $this->model(),
            'instructions' => $system,
            'input' => $user,
            'temperature' => 0.8,
        ];

        // OpenAI rejects JSON mode combined with the web_search tool.
        if ($withWebSearch) {
            $parameters['instructions'] = $system."\n\nRespond with a single valid JSON object only, no markdown fences or extra text.";
            $parameters['tools'] = [['type' => 'web_search']];
            $parameters['max_tool_calls'] = 1;
        } else {
            $parameters['text'] = ['format' => ['type' => 'json_object']];
        }

        try {
            $response = OpenAI::responses()->create($parameters);
        } catch (\Throwable $e) {
            Log::error('OpenAI responses request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('OpenAI request failed: '.$e->getMessage(), previous: $e);
        }

        return $this->parseResponsesJson($response);
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     web_search_calls: int
     * }
     */
    private function parseResponsesJson(CreateResponse $response): array
    {
        $content = trim($response->outputText ?? '');

        if ($content === '') {
            throw new RuntimeException('OpenAI returned empty content.');
        }

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $parsed = json_decode(substr($content, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        return [
            'parsed' => $parsed,
            'input_tokens' => $response->usage?->inputTokens ?? 0,
            'output_tokens' => $response->usage?->outputTokens ?? 0,
            'total_tokens' => $response->usage?->totalTokens ?? 0,
            'web_search_calls' => $this->countWebSearchCalls($response->output),
        ];
    }

    /**
     * @param  array<int, mixed>  $output
     */
    private function countWebSearchCalls(array $output): int
    {
        $count = 0;

        foreach ($output as $item) {
            if ($item instanceof OutputWebSearchToolCall && $item->action?->type === 'search') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<mixed>  $variants
     * @return list<array{content: string, virality_score: int, estimated_reach: string}>
     */
    private function normalizeVariants(array $variants): array
    {
        $normalized = [];

        foreach ($variants as $variant) {
            if (! is_array($variant) || empty($variant['content'])) {
                continue;
            }

            $score = max(0, min(100, (int) ($variant['virality_score'] ?? 70)));
            $reach = $variant['estimated_reach'] ?? 'medium';
            if (! in_array($reach, ['low', 'medium', 'high'], true)) {
                $reach = 'medium';
            }

            $angle = trim((string) ($variant['angle'] ?? ''));

            $normalized[] = [
                'content' => trim((string) $variant['content']),
                'angle' => $angle === '' ? null : mb_substr($angle, 0, 80),
                'virality_score' => $score,
                'estimated_reach' => $reach,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<mixed>  $drafts
     * @return list<array{content: string, tone: string}>
     */
    private function normalizeReplyDrafts(array $drafts, string $tone): array
    {
        $normalized = [];

        foreach ($drafts as $draft) {
            if (! is_array($draft) || empty($draft['content'])) {
                continue;
            }

            $normalized[] = [
                'content' => trim((string) $draft['content']),
                'tone' => (string) ($draft['tone'] ?? $tone),
            ];
        }

        return $normalized;
    }
}
