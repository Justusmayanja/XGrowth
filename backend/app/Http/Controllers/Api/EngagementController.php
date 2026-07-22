<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AICore\AICoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EngagementController extends Controller
{
    public function __construct(
        private readonly AICoreService $aiCore,
    ) {}

    public function feed(Request $request): JsonResponse
    {
        $profile = $request->user()->accountProfile;
        $niche = $profile?->resolvedNiche() ?? 'your niche';
        $tone = $profile?->primaryTone() ?? 'professional';

        $templates = [
            [
                'id' => 'mock-1',
                'author' => '@niche_insider',
                'author_followers' => 28400,
                'content' => "Unpopular opinion: the conversation around {$niche} is missing the real story. Everyone's reacting to headlines — not what's actually happening.",
                'likes' => 1240,
                'reposts' => 318,
                'replies' => 89,
                'minutes_ago' => 12,
                'opportunity_score' => 88,
            ],
            [
                'id' => 'mock-2',
                'author' => '@trend_watch',
                'author_followers' => 51200,
                'content' => "This is blowing up faster than expected. If you're building in {$niche}, now is the moment to say something useful — not generic.",
                'likes' => 890,
                'reposts' => 201,
                'replies' => 56,
                'minutes_ago' => 28,
                'opportunity_score' => 79,
            ],
            [
                'id' => 'mock-3',
                'author' => '@daily_pulse',
                'author_followers' => 9800,
                'content' => "Genuine question for people in {$niche}: what's the one take nobody wants to say out loud?",
                'likes' => 456,
                'reposts' => 67,
                'replies' => 142,
                'minutes_ago' => 45,
                'opportunity_score' => 91,
            ],
        ];

        return response()->json([
            'posts' => $templates,
            'stats' => [
                'replies_sent_today' => 0,
                'profile_visits_from_replies' => 0,
                'followers_gained_from_engagement' => 0,
            ],
            'note' => "Sample feed tailored to {$niche}. Reply drafts use live OpenAI; live X data connects in a later stage.",
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
