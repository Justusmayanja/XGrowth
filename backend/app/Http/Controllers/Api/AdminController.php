<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountProfile;
use App\Models\AiDecision;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\Trend;
use App\Models\User;
use App\Services\Admin\AdminDemoDataService;
use App\Services\GrowthAgent\GrowthAgentService;
use App\Services\GrowthAgent\SafetyEngineService;
use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminController extends Controller
{
    public function overview(): JsonResponse
    {
        $customers = User::query()->where('is_admin', false);

        $managedCount = (clone $customers)
            ->whereHas('accountProfile', fn ($q) => $q->where('onboarding_completed', true))
            ->whereHas('connectedAccounts', fn ($q) => $q->where('platform', 'x')->where('is_active', true))
            ->count();

        $needsSetup = (clone $customers)
            ->where(function ($q) {
                $q->whereDoesntHave('accountProfile', fn ($p) => $p->where('onboarding_completed', true))
                    ->orWhereDoesntHave('connectedAccounts', fn ($c) => $c->where('platform', 'x')->where('is_active', true));
            })
            ->count();

        $customerIds = (clone $customers)->pluck('id');

        $decisionBreakdown = AiDecision::query()
            ->whereIn('user_id', $customerIds)
            ->select('decision', DB::raw('count(*) as count'))
            ->groupBy('decision')
            ->pluck('count', 'decision');

        $planBreakdown = (clone $customers)
            ->select('subscription_plan', DB::raw('count(*) as count'))
            ->groupBy('subscription_plan')
            ->pluck('count', 'subscription_plan');

        return response()->json([
            'stats' => [
                'customers_total' => $customers->count(),
                'signups_7d' => (clone $customers)->where('created_at', '>=', now()->subDays(7))->count(),
                'managed_accounts' => $managedCount,
                'needs_setup' => $needsSetup,
                'blocked' => (clone $customers)->where('is_blocked', true)->count(),
                'posts_total' => Post::whereIn('user_id', $customerIds)->count(),
                'posts_posted' => Post::whereIn('user_id', $customerIds)->where('status', 'posted')->count(),
                'decisions_24h' => AiDecision::whereIn('user_id', $customerIds)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'total_impressions' => (int) Post::whereIn('user_id', $customerIds)->sum('impressions'),
            ],
            'plan_breakdown' => $planBreakdown,
            'decision_breakdown' => $decisionBreakdown,
            'recent_customers' => $this->customersQuery()
                ->with([
                    'accountProfile:id,user_id,onboarding_completed,niche,custom_niche,mode,automation_level',
                    'connectedAccounts' => fn ($q) => $q
                        ->where('platform', 'x')
                        ->where('is_active', true)
                        ->select('id', 'user_id', 'username'),
                ])
                ->withCount(['posts', 'aiDecisions as decisions_count'])
                ->latest()
                ->limit(8)
                ->get(['id', 'name', 'email', 'subscription_plan', 'created_at', 'is_blocked'])
                ->map(fn (User $user) => $this->formatCustomer($user)),
            'needs_attention' => $this->customersQuery()
                ->with([
                    'accountProfile:id,user_id,onboarding_completed,niche',
                    'connectedAccounts' => fn ($q) => $q
                        ->where('platform', 'x')
                        ->where('is_active', true)
                        ->select('id', 'user_id', 'username'),
                ])
                ->where(function ($q) {
                    $q->whereDoesntHave('accountProfile', fn ($p) => $p->where('onboarding_completed', true))
                        ->orWhereDoesntHave('connectedAccounts', fn ($c) => $c->where('platform', 'x')->where('is_active', true));
                })
                ->latest()
                ->limit(6)
                ->get(['id', 'name', 'email', 'subscription_plan', 'created_at', 'is_blocked'])
                ->map(fn (User $user) => $this->formatCustomer($user)),
            'recent_activity' => AiDecision::query()
                ->whereIn('user_id', $customerIds)
                ->with('user:id,name,email')
                ->latest()
                ->limit(10)
                ->get(['id', 'user_id', 'decision', 'action_taken', 'opportunity_score', 'reason', 'created_at']),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $query = $this->customersQuery()
            ->with([
                'accountProfile:id,user_id,niche,custom_niche,onboarding_completed,mode,automation_level,goal',
                'connectedAccounts' => fn ($q) => $q
                    ->where('platform', 'x')
                    ->where('is_active', true)
                    ->select('id', 'user_id', 'username', 'platform', 'is_active'),
            ])
            ->withCount(['posts', 'aiDecisions as decisions_count'])
            ->latest();

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($plan = $request->string('plan')->trim()->toString()) {
            $query->where('subscription_plan', $plan);
        }

        if ($status = $request->string('status')->trim()->toString()) {
            match ($status) {
                'managed' => $query
                    ->where('is_blocked', false)
                    ->whereHas('accountProfile', fn ($q) => $q->where('onboarding_completed', true))
                    ->whereHas('connectedAccounts', fn ($q) => $q->where('platform', 'x')->where('is_active', true)),
                'needs_onboarding' => $query
                    ->where('is_blocked', false)
                    ->whereDoesntHave(
                        'accountProfile',
                        fn ($q) => $q->where('onboarding_completed', true)
                    ),
                'needs_x' => $query
                    ->where('is_blocked', false)
                    ->whereHas('accountProfile', fn ($q) => $q->where('onboarding_completed', true))
                    ->whereDoesntHave('connectedAccounts', fn ($q) => $q->where('platform', 'x')->where('is_active', true)),
                'blocked' => $query->where('is_blocked', true),
                default => null,
            };
        }

        $paginator = $query->paginate(20);
        $paginator->getCollection()->transform(fn (User $user) => $this->formatCustomer($user));

        return response()->json(['customers' => $paginator]);
    }

    /** @deprecated Use customers() — kept for compatibility */
    public function users(Request $request): JsonResponse
    {
        $response = $this->customers($request)->getData(true);
        $response['users'] = $response['customers'];
        unset($response['customers']);

        return response()->json($response);
    }

    public function showCustomer(User $user): JsonResponse
    {
        if ($user->is_admin) {
            return response()->json(['message' => 'Platform operators are not customer accounts.'], 422);
        }

        $user->load([
            'accountProfile',
            'connectedAccounts' => fn ($q) => $q
                ->where('platform', 'x')
                ->select('id', 'user_id', 'username', 'platform', 'is_active', 'created_at'),
            'blockedBy:id,name,email',
        ])->loadCount(['posts', 'aiDecisions as decisions_count']);

        $recentPosts = Post::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get(['id', 'content', 'status', 'impressions', 'created_at']);

        $recentDecisions = AiDecision::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(15)
            ->get(['id', 'decision', 'action_taken', 'opportunity_score', 'reason', 'created_at']);

        $postStats = Post::query()
            ->where('user_id', $user->id)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $decisionStats = AiDecision::query()
            ->where('user_id', $user->id)
            ->select('decision', DB::raw('count(*) as count'))
            ->groupBy('decision')
            ->pluck('count', 'decision');

        return response()->json([
            'customer' => [
                ...$this->formatCustomer($user),
                'admin_notes' => $user->admin_notes,
                'blocked_at' => $user->blocked_at,
                'blocked_reason' => $user->blocked_reason,
                'blocked_by' => $user->blockedBy
                    ? ['id' => $user->blockedBy->id, 'name' => $user->blockedBy->name, 'email' => $user->blockedBy->email]
                    : null,
                'recent_posts' => $recentPosts,
                'recent_decisions' => $recentDecisions,
                'post_stats' => $postStats,
                'decision_stats' => $decisionStats,
            ],
        ]);
    }

    public function updateCustomer(Request $request, User $user): JsonResponse
    {
        if ($user->is_admin) {
            return response()->json(['message' => 'Platform operators are not customer accounts.'], 422);
        }

        $validated = $request->validate([
            'subscription_plan' => ['sometimes', 'string', 'in:free,starter,creator,pro,agency'],
            'is_blocked' => ['sometimes', 'boolean'],
            'blocked_reason' => ['nullable', 'string', 'max:1000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        $updates = [];

        if (array_key_exists('subscription_plan', $validated)) {
            $updates['subscription_plan'] = $validated['subscription_plan'];
        }

        if (array_key_exists('admin_notes', $validated)) {
            $updates['admin_notes'] = $validated['admin_notes'];
        }

        if (array_key_exists('is_blocked', $validated)) {
            if ($validated['is_blocked']) {
                $updates['is_blocked'] = true;
                $updates['blocked_at'] = now();
                $updates['blocked_by'] = $request->user()->id;
                $updates['blocked_reason'] = $validated['blocked_reason'] ?? null;
            } else {
                $updates['is_blocked'] = false;
                $updates['blocked_at'] = null;
                $updates['blocked_by'] = null;
                $updates['blocked_reason'] = null;
            }
        } elseif (array_key_exists('blocked_reason', $validated) && $user->is_blocked) {
            $updates['blocked_reason'] = $validated['blocked_reason'];
        }

        $user->update($updates);

        if ($user->is_blocked) {
            $user->tokens()->delete();
        }

        return response()->json([
            'customer' => $this->formatCustomer($user->fresh(['accountProfile', 'connectedAccounts', 'blockedBy'])),
            'message' => $user->is_blocked ? 'Customer blocked and signed out.' : 'Customer updated.',
        ]);
    }

    /** @deprecated Use updateCustomer() */
    public function updateUser(Request $request, User $user): JsonResponse
    {
        return $this->updateCustomer($request, $user);
    }

    public function decisions(Request $request): JsonResponse
    {
        $customerIds = $this->customersQuery()->pluck('id');

        $query = AiDecision::query()
            ->whereIn('user_id', $customerIds)
            ->with('user:id,name,email')
            ->latest();

        if ($decision = $request->string('decision')->trim()->toString()) {
            $query->where('decision', strtoupper($decision));
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return response()->json([
            'decisions' => $query->paginate(25),
            'stats' => [
                'total' => AiDecision::whereIn('user_id', $customerIds)->count(),
                'last_24h' => AiDecision::whereIn('user_id', $customerIds)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'by_type' => AiDecision::query()
                    ->whereIn('user_id', $customerIds)
                    ->select('decision', DB::raw('count(*) as count'))
                    ->groupBy('decision')
                    ->pluck('count', 'decision'),
            ],
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        $customerIds = $this->customersQuery()->pluck('id');

        $query = Post::query()
            ->whereIn('user_id', $customerIds)
            ->with(['user:id,name,email', 'trend:id,name'])
            ->latest();

        if ($status = $request->string('status')->trim()->toString()) {
            $query->where('status', $status);
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return response()->json([
            'posts' => $query->paginate(25),
            'stats' => [
                'total' => Post::whereIn('user_id', $customerIds)->count(),
                'draft' => Post::whereIn('user_id', $customerIds)->where('status', 'draft')->count(),
                'posted' => Post::whereIn('user_id', $customerIds)->where('status', 'posted')->count(),
                'total_impressions' => (int) Post::whereIn('user_id', $customerIds)->sum('impressions'),
            ],
        ]);
    }

    public function operations(): JsonResponse
    {
        $customerIds = $this->customersQuery()->pluck('id');
        $queueDriver = config('queue.default');
        $pendingJobs = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        return response()->json([
            'environment' => app()->environment(),
            'queue_driver' => $queueDriver,
            'database_driver' => config('database.default'),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'counts' => [
                'customers' => $customerIds->count(),
                'managed' => User::query()
                    ->whereIn('id', $customerIds)
                    ->whereHas('accountProfile', fn ($q) => $q->where('onboarding_completed', true))
                    ->whereHas('connectedAccounts', fn ($q) => $q->where('platform', 'x')->where('is_active', true))
                    ->count(),
                'trend_catalog' => Trend::count(),
                'posts' => Post::whereIn('user_id', $customerIds)->count(),
                'decisions' => AiDecision::whereIn('user_id', $customerIds)->count(),
            ],
            'last_trend_sync_at' => Trend::max('detected_at'),
            'last_engine_run_at' => AiDecision::whereIn('user_id', $customerIds)->max('created_at'),
            'last_signup_at' => User::query()->whereIn('id', $customerIds)->max('created_at'),
        ]);
    }

    /** @deprecated Use operations() */
    public function system(): JsonResponse
    {
        return $this->operations();
    }

    public function seedTrends(): JsonResponse
    {
        $trends = app(TrendEngineService::class)->seedMockTrends();

        return response()->json([
            'message' => 'Trend catalog refreshed.',
            'count' => count($trends),
        ]);
    }

    public function seedDemo(Request $request): JsonResponse
    {
        $result = app(AdminDemoDataService::class)->seed($request->boolean('force'));

        return response()->json([
            'message' => 'Demo customers seeded.',
            ...$result,
        ]);
    }

    public function evaluateAll(): JsonResponse
    {
        $count = app(GrowthAgentService::class)->evaluateAllUsers();

        return response()->json([
            'message' => "Growth Engine evaluated {$count} managed account(s).",
            'count' => $count,
        ]);
    }

    public function resetSafety(): JsonResponse
    {
        $deleted = app(SafetyEngineService::class)->resetDailyBudgets();

        return response()->json([
            'message' => "Removed {$deleted} expired safety budget row(s).",
            'deleted' => $deleted,
        ]);
    }

    private function customersQuery()
    {
        return User::query()->where('is_admin', false);
    }

    private function formatCustomer(User $user): array
    {
        $xAccount = $user->connectedAccounts
            ?->first(fn ($a) => $a->platform === 'x' && $a->is_active);

        $onboarded = (bool) $user->accountProfile?->onboarding_completed;
        $hasX = $xAccount !== null;

        $status = match (true) {
            ! $onboarded => 'needs_onboarding',
            ! $hasX => 'needs_x_connection',
            default => 'managed',
        };

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'subscription_plan' => $user->subscription_plan,
            'created_at' => $user->created_at,
            'is_blocked' => (bool) $user->is_blocked,
            'blocked_at' => $user->blocked_at,
            'blocked_reason' => $user->blocked_reason,
            'posts_count' => $user->posts_count ?? $user->posts()->count(),
            'decisions_count' => $user->decisions_count ?? $user->aiDecisions()->count(),
            'account_status' => $status,
            'x_username' => $xAccount?->username,
            'account_profile' => $user->accountProfile,
            'connected_accounts' => $user->connectedAccounts,
        ];
    }
}
