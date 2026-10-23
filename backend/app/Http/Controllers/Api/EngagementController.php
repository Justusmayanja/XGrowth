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
        $profile = $request->user()->accountProfile;
        $niche = $profile?->resolvedNiche() ?? '';
        $account = $request->user()->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->first();

        $stats = [
            'replies_sent_today' => 0,
            'profile_visits_from_replies' => 0,
            'followers_gained_from_engagement' => 0,
        ];

        if (! $account) {
            return response()->json([
                'posts' => [],
                'stats' => $stats,
                'note' => 'Connect X to see conversations in your niche.',
            ]);
        }

        $feed = $this->x->replyFeed($account, $niche);
        foreach ($feed['errors'] as $error) {
            report(new RuntimeException($error));
        }

        if ($feed['posts'] === [] && $feed['errors'] !== []) {
            return response()->json([
                'posts' => [],
                'stats' => $stats,
                'note' => 'X did not return conversations. '.$feed['errors'][0],
            ]);
        }

        return response()->json([
            'posts' => $feed['posts'],
            'stats' => $stats,
            'note' => $feed['posts'] === []
                ? 'No replies, mentions, or matching posts came back from X.'
                : null,
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
