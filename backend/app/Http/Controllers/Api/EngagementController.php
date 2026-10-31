<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Models\ConnectedAccount;
use App\Services\AICore\AICoreService;
use App\Services\ApiSpend\ApiSpendService;
use App\Services\GrowthAgent\AccountDeskService;
use App\Services\GrowthAgent\XGoalPlan;
use App\Services\XApi\XApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class EngagementController extends Controller
{
    public function __construct(
        private readonly AICoreService $aiCore,
        private readonly XApiService $x,
        private readonly ApiSpendService $budget,
        private readonly AccountDeskService $desk,
        private readonly XGoalPlan $goals,
    ) {}

    public function feed(Request $request): JsonResponse
    {
        $account = $this->desk->select(
            $request->user(),
            is_numeric($request->query('account')) ? (int) $request->query('account') : null,
        );

        $stats = [
            'conversations' => 0,
            'likes' => 0,
            'reposts' => 0,
            'replies' => 0,
            'impressions' => 0,
        ];

        if (! $account) {
            return response()->json([
                'posts' => [],
                'circle' => [],
                'record' => ['answered' => [], 'skipped' => []],
                'stats' => $stats,
                'note' => 'Connect X to load mentions and replies from the account.',
            ]);
        }

        $profile = $request->user()->accountProfile;
        $phrases = $account ? array_values(array_filter([
            $account->nicheFor($profile),
            $account->descriptionFor($profile),
            $account->audienceFor($profile),
            $profile?->goalSentenceFor('x'),
        ])) : [];
        $purpose = implode(' ', $phrases);
        $feed = $this->x->replyFeed($account);
        foreach ($feed['errors'] as $error) {
            report(new RuntimeException($error));
        }

        $posts = [];
        foreach ($feed['posts'] as $post) {
            $post['fits'] = $phrases !== [] && $this->x->sharesAny((string) ($post['content'] ?? ''), $phrases);
            $posts[] = $post;
        }

        $topics = $purpose === '' ? [] : $this->x->homeFeed(
            $account,
            $account->nicheFor($profile),
            trim($account->descriptionFor($profile).' '.$account->audienceFor($profile)),
        );
        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $text = trim((string) ($topic['text'] ?? $topic['name'] ?? ''));
            $id = trim((string) ($topic['tweet_id'] ?? ''));
            if ($text === '' || $id === '' || ! $this->x->sharesAny($text, $phrases)) {
                continue;
            }
            $posts[] = [
                'id' => $id,
                'author' => (string) ($topic['author'] ?? 'Someone in this circle'),
                'author_followers' => (int) ($topic['author_followers'] ?? 0),
                'content' => $text,
                'likes' => (int) ($topic['likes'] ?? 0),
                'reposts' => (int) ($topic['reposts'] ?? 0),
                'replies' => (int) ($topic['replies'] ?? 0),
                'impressions' => (int) ($topic['impressions'] ?? 0),
                'minutes_ago' => (int) ($topic['minutes_ago'] ?? 0),
                'kind' => 'ambition',
                'fits' => true,
            ];
        }

        usort($posts, function (array $a, array $b) {
            $fit = ((bool) ($b['fits'] ?? false)) <=> ((bool) ($a['fits'] ?? false));

            return $fit !== 0 ? $fit : ((int) ($a['minutes_ago'] ?? 0)) <=> ((int) ($b['minutes_ago'] ?? 0));
        });

        $seen = [];
        $unique = [];
        foreach ($posts as $post) {
            $id = (string) ($post['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $post;
            $stats['conversations']++;
            $stats['likes'] += (int) ($post['likes'] ?? 0);
            $stats['reposts'] += (int) ($post['reposts'] ?? 0);
            $stats['replies'] += (int) ($post['replies'] ?? 0);
            $stats['impressions'] += (int) ($post['impressions'] ?? 0);
        }

        $full = $this->budget->showsFullDesk((int) $request->user()->id);
        $circle = $full ? $this->circle($topics, $account) : [];

        if ($unique === [] && $feed['errors'] !== []) {
            return response()->json([
                'posts' => [],
                'circle' => $circle,
                'record' => $this->desk->replyRecord($request->user(), $account),
                'stats' => $stats,
                'note' => 'X did not return mentions or replies. '.$feed['errors'][0],
            ]);
        }

        $matched = count(array_filter($unique, fn (array $post) => (bool) ($post['fits'] ?? false)));
        $note = $unique === []
            ? 'No mentions or replies came back. This check is kept for seven days.'
            : ($matched > 0
                ? 'People whose posts match what this account is for come first. Replies stay in this voice, with no link.'
                : 'Mentions are here. Nothing on the home timeline matched this account yet.');

        return response()->json([
            'posts' => array_slice($unique, 0, 8),
            'circle' => $circle,
            'record' => $this->desk->replyRecord($request->user(), $account),
            'stats' => $stats,
            'note' => $note,
        ]);
    }

    /**
     * Larger accounts already on this home timeline, and how their latest post did.
     *
     * @param  list<array<string, mixed>>  $topics
     * @return list<array{author: string, followers: int, likes: int, replies: int, impressions: int, content: string}>
     */
    private function circle(array $topics, ConnectedAccount $account): array
    {
        $cached = Cache::get('x-activity:v8:'.$account->id);
        $own = is_array($cached) ? (int) ($cached['followers'] ?? 0) : 0;
        $byAuthor = [];
        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                continue;
            }
            $author = trim((string) ($topic['author'] ?? ''));
            $followers = (int) ($topic['author_followers'] ?? 0);
            if ($author === '' || $followers < 1) {
                continue;
            }
            if ($own > 0 && $followers <= $own) {
                continue;
            }
            $current = $byAuthor[$author] ?? null;
            if ($current !== null && (int) $current['followers'] >= $followers) {
                continue;
            }
            $byAuthor[$author] = [
                'author' => $author,
                'followers' => $followers,
                'likes' => (int) ($topic['likes'] ?? 0),
                'replies' => (int) ($topic['replies'] ?? 0),
                'impressions' => (int) ($topic['impressions'] ?? 0),
                'content' => trim((string) ($topic['text'] ?? $topic['name'] ?? '')),
            ];
        }

        $rows = array_values($byAuthor);
        usort($rows, fn (array $a, array $b) => $b['followers'] <=> $a['followers']);

        return array_slice($rows, 0, 3);
    }

    /**
     * @return list<string>
     */
    private function phrases(?AccountProfile $profile): array
    {
        if (! $profile) {
            return [];
        }

        return array_values(array_filter([
            $profile->resolvedNiche(),
            (string) $profile->description,
            (string) $profile->audience,
            $profile->goalSentenceFor('x'),
        ]));
    }

    public function replyDrafts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'context' => ['nullable', 'string', 'max:2000'],
            'account' => ['nullable', 'integer'],
            'use_web_search' => ['nullable', 'boolean'],
        ]);

        $profile = $request->user()->accountProfile;
        $account = $this->desk->select($request->user(), isset($validated['account']) ? (int) $validated['account'] : null);
        $tone = $profile?->primaryTone() ?? 'professional';
        $context = $validated['context'] ?? 'this conversation';
        $voice = $account
            ? trim($account->descriptionFor($profile).' '.$account->audienceFor($profile).' '.($profile?->goalSentenceFor('x') ?? ''))
            : trim(($profile?->description ?? '').' '.($profile?->audience ?? '').' '.($profile?->goalSentenceFor('x') ?? ''));
        $niche = $account?->nicheFor($profile) ?: ($profile?->resolvedNiche() ?? 'general');

        try {
            $drafts = $this->aiCore->generateReplyDrafts([
                'user' => $request->user(),
                'context' => $profile ? $this->goals->replyBrief($profile) : 'Answer this post the way this account would. One or two sentences. No pitch and no link.',
                'tone' => $tone,
                'niche' => $niche,
                'post_content' => $context,
                'voice' => $voice,
                'allow_link' => $profile ? $this->goals->allowsLink($profile) : false,
                'use_web_search' => (bool) ($validated['use_web_search'] ?? false),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                str_contains(strtolower($e->getMessage()), 'limit reached') ? 429 : 503
            );
        }

        if ($profile) {
            foreach ($drafts as $index => $draft) {
                $drafts[$index]['content'] = $this->goals->finishText($profile, (string) ($draft['content'] ?? ''));
            }
        }

        return response()->json(['drafts' => $drafts]);
    }
}
