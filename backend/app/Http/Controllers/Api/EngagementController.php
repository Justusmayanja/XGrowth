<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AICore\AICoreService;
use App\Services\XApi\XApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EngagementController extends Controller
{
    public function __construct(
        private readonly AICoreService $aiCore,
        private readonly XApiService $x,
    ) {}

    public function feed(Request $request): JsonResponse
    {
        $account = $request->user()->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

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
                'stats' => $stats,
                'note' => 'Connect X to load mentions and replies from the account.',
            ]);
        }

        $feed = $this->x->replyFeed($account);
        foreach ($feed['errors'] as $error) {
            report(new RuntimeException($error));
        }

        foreach ($feed['posts'] as $post) {
            $stats['conversations']++;
            $stats['likes'] += (int) ($post['likes'] ?? 0);
            $stats['reposts'] += (int) ($post['reposts'] ?? 0);
            $stats['replies'] += (int) ($post['replies'] ?? 0);
            $stats['impressions'] += (int) ($post['impressions'] ?? 0);
        }

        if ($feed['posts'] === [] && $feed['errors'] !== []) {
            return response()->json([
                'posts' => [],
                'stats' => $stats,
                'note' => 'X did not return mentions or replies. '.$feed['errors'][0],
            ]);
        }

        return response()->json([
            'posts' => $feed['posts'],
            'stats' => $stats,
            'note' => $feed['posts'] === []
                ? 'No mentions or replies came back. This check is kept for seven days.'
                : 'Results for the last seven days. Mentions are fetched again when the week ends.',
        ]);
    }

    public function replyDrafts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'context' => ['nullable', 'string', 'max:2000'],
            'use_web_search' => ['nullable', 'boolean'],
        ]);

        $profile = $request->user()->accountProfile;
        $tone = $profile?->primaryTone() ?? 'professional';
        $context = $validated['context'] ?? 'this conversation';

        try {
            $drafts = $this->aiCore->generateReplyDrafts([
                'user' => $request->user(),
                'context' => $context,
                'tone' => $tone,
                'niche' => $profile?->resolvedNiche() ?? 'general',
                'post_content' => $context,
                'use_web_search' => (bool) ($validated['use_web_search'] ?? false),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                str_contains(strtolower($e->getMessage()), 'limit reached') ? 429 : 503
            );
        }

        return response()->json(['drafts' => $drafts]);
    }
}
