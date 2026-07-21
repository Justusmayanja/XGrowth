<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngagementController extends Controller
{
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
            'note' => "Placeholder feed tailored to {$niche}. Live X data connects in the final API stage.",
        ]);
    }

    public function replyDrafts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'context' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = $request->user()->accountProfile;
        $tone = $profile?->primaryTone() ?? 'professional';
        $context = $validated['context'] ?? 'this conversation';

        $drafts = [
            [
                'content' => "Strong point. I'd add one thing most people miss about {$context} — the second-order effect matters more than the headline.",
                'tone' => $tone,
            ],
            [
                'content' => "This is the right question. In my experience with {$profile?->resolvedNiche()}, the answer depends on timing more than people admit.",
                'tone' => $tone,
            ],
            [
                'content' => "Disagree respectfully — there's a nuance here that changes the whole read. Happy to explain if useful.",
                'tone' => $tone,
            ],
        ];

        return response()->json(['drafts' => $drafts]);
    }
}
