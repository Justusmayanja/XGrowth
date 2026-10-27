<?php

use App\Http\Controllers\Api\AccountProfileController;
use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\AccountSetupController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiRuleController;
use App\Http\Controllers\Api\AiUsageController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingWebhookController;
use App\Http\Controllers\Api\ConnectedAccountController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\GrowthAgentController;
use App\Http\Controllers\Api\PlatformSettingsController;
use App\Http\Controllers\Api\SafetyController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\TikTokController;
use App\Http\Controllers\Api\YouTubeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => 'Xpulse API',
    'version' => '0.1.0',
]));

Route::get('/plans', [SubscriptionPlanController::class, 'index']);

Route::get('/billing/catalog', [BillingController::class, 'catalog']);
Route::post('/billing/quote', [BillingController::class, 'quote']);
Route::post('/billing/webhooks/paddle', [BillingWebhookController::class, 'paddle']);
Route::post('/billing/webhooks/flutterwave', [BillingWebhookController::class, 'flutterwave']);

Route::prefix('auth')->group(function () {
    Route::post('/email/send', [AuthController::class, 'sendCode'])->middleware('throttle:8,1');
    Route::post('/email/verify', [AuthController::class, 'verifyCode'])->middleware('throttle:20,1');
    Route::get('/x/callback', function (Request $request) {
        return app(SocialAuthController::class)->callback($request, 'x');
    });
});

Route::middleware(['auth:sanctum', 'not_blocked'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/admin/access', fn (Request $request) => response()->json([
        'is_admin' => (bool) $request->user()->is_admin,
    ]));

    Route::middleware('customer')->group(function () {
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('/dashboard/opportunities', [DashboardController::class, 'opportunities']);
        Route::get('/dashboard/platform/{platform}', [DashboardController::class, 'platform'])
            ->whereIn('platform', ['x', 'tiktok', 'youtube']);
        Route::get('/trends', [DashboardController::class, 'trends']);

        Route::get('/account/profile', [AccountProfileController::class, 'show']);
        Route::post('/account/profile', [AccountProfileController::class, 'store']);
        Route::put('/account/timezone', [AccountProfileController::class, 'timezone']);
        Route::get('/account/setup', [AccountSetupController::class, 'show']);
        Route::post('/account/setup/platforms', [AccountSetupController::class, 'platforms']);
        Route::post('/account/setup/about', [AccountSetupController::class, 'about']);
        Route::post('/account/setup/niche-ideas', [AccountSetupController::class, 'nicheIdeas']);
        Route::post('/account/setup/platforms/{platform}', [AccountSetupController::class, 'platform'])
            ->whereIn('platform', ['x', 'tiktok', 'youtube']);
        Route::get('/account/settings', [AccountSettingsController::class, 'show']);
        Route::get('/account/ai-usage', [AiUsageController::class, 'show']);
        Route::patch('/account', [AccountSettingsController::class, 'updateAccount']);
        Route::patch('/account/automation', [AccountSettingsController::class, 'updateAutomation']);
        Route::patch('/account/notifications', [AccountSettingsController::class, 'updateNotifications']);
        Route::post('/account/revoke-sessions', [AccountSettingsController::class, 'revokeSessions']);

        Route::post('/content/generate', [ContentController::class, 'generate']);
        Route::post('/content/drafts', [ContentController::class, 'saveDraft']);
        Route::post('/content/drafts/{post}/publish', [ContentController::class, 'publish']);
        Route::put('/content/drafts/{post}', [ContentController::class, 'updateDraft']);
        Route::delete('/content/drafts/{post}', [ContentController::class, 'destroy']);
        Route::get('/content/posts', [ContentController::class, 'index']);

        Route::post('/tiktok/run', [TikTokController::class, 'run'])->middleware('throttle:6,1');
        Route::post('/tiktok/clips/{clip}/confirm', [TikTokController::class, 'confirm']);
        Route::post('/tiktok/clips/{clip}/results', [TikTokController::class, 'results']);
        Route::post('/tiktok/clips/{clip}/discard', [TikTokController::class, 'discard']);
        Route::post('/tiktok/clips/{clip}/reschedule', [TikTokController::class, 'reschedule']);

        Route::post('/youtube/run', [YouTubeController::class, 'run']);
        Route::post('/youtube/footage', [YouTubeController::class, 'footage']);
        Route::post('/youtube/videos/{video}/confirm', [YouTubeController::class, 'confirm']);
        Route::post('/youtube/videos/{video}/results', [YouTubeController::class, 'results']);

        Route::get('/growth-agent/settings', [GrowthAgentController::class, 'settings']);
        Route::put('/growth-agent/settings', [GrowthAgentController::class, 'updateSettings']);
        Route::post('/growth-agent/evaluate', [GrowthAgentController::class, 'evaluate']);
        Route::get('/growth-agent/feed', [GrowthAgentController::class, 'feed']);

        Route::get('/safety/status', [SafetyController::class, 'status']);

        Route::get('/engagement/feed', [EngagementController::class, 'feed']);
        Route::post('/engagement/reply-drafts', [EngagementController::class, 'replyDrafts']);

        Route::get('/analytics/growth', [AnalyticsController::class, 'growth']);

        Route::get('/connected-accounts', [ConnectedAccountController::class, 'index']);
        Route::get('/connected-accounts/x/status', [ConnectedAccountController::class, 'connectStatus']);
        Route::delete('/connected-accounts/x', [ConnectedAccountController::class, 'disconnect']);
    });

    Route::post('/billing/preview', [BillingController::class, 'preview']);
    Route::post('/billing/checkout', [BillingController::class, 'checkout']);
    Route::post('/billing/cancel', [BillingController::class, 'cancel']);
    Route::get('/billing/subscription', [BillingController::class, 'show']);
    Route::post('/billing/subscriptions/{subscription}/confirm', [BillingController::class, 'confirm']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/customers', [AdminController::class, 'customers']);
        Route::get('/customers/{user}', [AdminController::class, 'showCustomer']);
        Route::patch('/customers/{user}', [AdminController::class, 'updateCustomer']);
        Route::get('/decisions', [AdminController::class, 'decisions']);
        Route::get('/posts', [AdminController::class, 'posts']);
        Route::get('/operations', [AdminController::class, 'operations']);
        Route::get('/system', [AdminController::class, 'system']);
        Route::post('/trends/seed', [AdminController::class, 'seedTrends']);
        Route::post('/seed-demo', [AdminController::class, 'seedDemo']);
        Route::post('/evaluate-all', [AdminController::class, 'evaluateAll']);
        Route::post('/reset-safety', [AdminController::class, 'resetSafety']);
        Route::get('/plans', [SubscriptionPlanController::class, 'index']);
        Route::patch('/plans', [SubscriptionPlanController::class, 'bulkUpdate']);
        Route::patch('/plans/{slug}', [SubscriptionPlanController::class, 'update']);
        Route::get('/ai-rules', [AiRuleController::class, 'index']);
        Route::patch('/ai-rules', [AiRuleController::class, 'bulkUpdate']);
        Route::get('/platform-settings', [PlatformSettingsController::class, 'index']);
        Route::patch('/platform-settings', [PlatformSettingsController::class, 'bulkUpdate']);
        Route::get('/billing/prices', [BillingController::class, 'adminPrices']);
        Route::patch('/billing/prices', [BillingController::class, 'updatePrices']);
    });
});
