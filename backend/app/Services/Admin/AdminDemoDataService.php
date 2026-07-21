<?php

namespace App\Services\Admin;

use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\Trend;
use App\Models\User;
use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminDemoDataService
{
    public function seed(bool $force = false): array
    {
        $trends = app(TrendEngineService::class)->seedMockTrends();
        $trendIds = collect($trends)->pluck('id', 'slug');

        $catalog = [
            [
                'name' => 'Alex Rivera',
                'email' => 'alex@xpulse.dev',
                'plan' => 'pro',
                'is_admin' => true,
                'x_username' => 'alexrivera',
                'profile' => [
                    'goal' => 'grow_audience',
                    'niche' => 'technology',
                    'tone' => 'professional',
                    'mode' => 'reactive',
                    'automation_level' => 3,
                    'onboarding_completed' => true,
                ],
            ],
            [
                'name' => 'Sarah Chen',
                'email' => 'sarah@example.com',
                'plan' => 'creator',
                'is_admin' => false,
                'x_username' => 'sarahchen',
                'profile' => [
                    'goal' => 'thought_leadership',
                    'niche' => 'business',
                    'tone' => 'bold',
                    'mode' => 'proactive',
                    'automation_level' => 2,
                    'onboarding_completed' => true,
                ],
            ],
            [
                'name' => 'Mike Okafor',
                'email' => 'mike@example.com',
                'plan' => 'starter',
                'is_admin' => false,
                'x_username' => 'mikeokafor',
                'profile' => [
                    'goal' => 'grow_audience',
                    'niche' => 'football',
                    'tone' => 'casual',
                    'mode' => 'reactive',
                    'automation_level' => 1,
                    'onboarding_completed' => true,
                ],
            ],
            [
                'name' => 'Jane Park',
                'email' => 'jane@example.com',
                'plan' => 'free',
                'is_admin' => false,
                'x_username' => null,
                'profile' => [
                    'goal' => 'grow_audience',
                    'niche' => 'motivation',
                    'tone' => 'friendly',
                    'mode' => 'reactive',
                    'automation_level' => 1,
                    'onboarding_completed' => false,
                ],
            ],
            [
                'name' => 'Dev Agency Co',
                'email' => 'agency@example.com',
                'plan' => 'agency',
                'is_admin' => false,
                'x_username' => 'devagencyco',
                'profile' => [
                    'goal' => 'client_growth',
                    'niche' => 'technology',
                    'custom_niche' => 'SaaS marketing',
                    'tone' => 'professional',
                    'mode' => 'proactive',
                    'automation_level' => 3,
                    'onboarding_completed' => true,
                ],
            ],
        ];

        $usersCreated = 0;
        $postsCreated = 0;
        $decisionsCreated = 0;

        foreach ($catalog as $item) {
            if (! $force && User::where('email', $item['email'])->exists()) {
                continue;
            }

            DB::transaction(function () use ($item, $trendIds, &$usersCreated, &$postsCreated, &$decisionsCreated) {
                $user = User::updateOrCreate(
                    ['email' => $item['email']],
                    [
                        'name' => $item['name'],
                        'password' => Hash::make('password'),
                        'subscription_plan' => $item['plan'],
                        'is_admin' => $item['is_admin'],
                        'email_verified_at' => now(),
                    ]
                );

                $connected = null;

                if ($item['x_username']) {
                    $connected = ConnectedAccount::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'platform' => 'x',
                            'platform_account_id' => $item['x_username'],
                        ],
                        [
                            'username' => $item['x_username'],
                            'access_token' => 'mock-access-token',
                            'refresh_token' => 'mock-refresh-token',
                            'token_expires_at' => now()->addDays(30),
                            'is_active' => true,
                        ]
                    );
                }

                AccountProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        ...$item['profile'],
                        'connected_account_id' => $connected?->id,
                        'audience' => 'Creators and founders',
                        'description' => "Demo profile for {$item['name']}",
                    ]
                );

                if ($item['profile']['onboarding_completed']) {
                    [$postCount, $decisionCount] = $this->seedUserActivity(
                        $user,
                        $connected,
                        $trendIds->values()->all()
                    );
                    $postsCreated += $postCount;
                    $decisionsCreated += $decisionCount;
                }

                $usersCreated++;
            });
        }

        return [
            'users' => $usersCreated,
            'trends' => count($trends),
            'posts' => $postsCreated,
            'decisions' => $decisionsCreated,
        ];
    }

    /**
     * @param  array<int>  $trendIds
     * @return array{0: int, 1: int}
     */
    private function seedUserActivity(User $user, ?ConnectedAccount $connected, array $trendIds): array
    {
        $postsCreated = 0;
        $decisionsCreated = 0;

        $draftSamples = [
            'The best time to post is when your niche is already talking. Stop guessing, start listening.',
            'Hot take: consistency matters less than timing when you are still under 10K followers.',
            'Three signs a trend is worth joining: rising velocity, low competition, and audience overlap.',
        ];

        $postedSamples = [
            'Match day energy is unreal today. Who are you backing?',
            'Just shipped a small feature that removes 80% of manual work. Details in the thread.',
            'Morning reminder: engagement beats impressions when you are building in public.',
        ];

        foreach ($draftSamples as $i => $content) {
            Post::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'content' => $content,
                    'status' => 'draft',
                ],
                [
                    'connected_account_id' => $connected?->id,
                    'trend_id' => $trendIds[$i % count($trendIds)] ?? null,
                    'content_type' => 'tweet',
                    'virality_predicted' => 55 + ($i * 7),
                ]
            );
            $postsCreated++;
        }

        foreach ($postedSamples as $i => $content) {
            Post::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'content' => $content,
                    'status' => 'posted',
                ],
                [
                    'connected_account_id' => $connected?->id,
                    'trend_id' => $trendIds[($i + 2) % count($trendIds)] ?? null,
                    'content_type' => 'tweet',
                    'platform_post_id' => 'mock-'.$user->id.'-'.$i,
                    'virality_predicted' => 70 + ($i * 5),
                    'impressions' => 1200 + ($i * 800),
                    'likes' => 45 + ($i * 12),
                    'reposts' => 8 + $i,
                    'replies' => 5 + $i,
                    'posted_at' => now()->subDays($i + 1),
                ]
            );
            $postsCreated++;
        }

        $decisionCatalog = [
            ['decision' => 'POST', 'action_taken' => 'draft_prepared', 'score' => 88, 'reason' => 'High opportunity on trending topic in your niche'],
            ['decision' => 'ENGAGE', 'action_taken' => 'monitor_conversation', 'score' => 74, 'reason' => 'Rising conversation worth a reply or quote'],
            ['decision' => 'WAIT', 'action_taken' => null, 'score' => 52, 'reason' => 'Opportunity below threshold, waiting for stronger signal'],
            ['decision' => 'POST', 'action_taken' => 'suggest_draft', 'score' => 85, 'reason' => 'Strong trend match, draft suggested in Content Studio'],
        ];

        foreach ($decisionCatalog as $i => $item) {
            $trendId = $trendIds[$i % count($trendIds)] ?? null;
            $trend = $trendId ? Trend::find($trendId) : null;

            AiDecision::create([
                'user_id' => $user->id,
                'connected_account_id' => $connected?->id,
                'decision' => $item['decision'],
                'action_taken' => $item['action_taken'],
                'opportunity_score' => $item['score'],
                'reason' => $item['reason'].($trend ? " on \"{$trend->name}\"" : ''),
                'context' => $trend ? [
                    'trend_id' => $trend->id,
                    'trend_name' => $trend->name,
                    'competition' => $trend->competition,
                    'growth_rate' => $trend->growth_rate,
                ] : [],
                'created_at' => now()->subHours($i * 6),
            ]);
            $decisionsCreated++;
        }

        return [$postsCreated, $decisionsCreated];
    }
}
