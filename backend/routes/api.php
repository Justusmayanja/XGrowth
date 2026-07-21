<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AccountProfileController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConnectedAccountController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\GrowthAgentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SafetyController;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => 'Xpulse API',
    'version' => '0.1.0',
]));

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'not_blocked'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/opportunities', [DashboardController::class, 'opportunities']);
    Route::get('/trends', [DashboardController::class, 'trends']);

    Route::get('/account/profile', [AccountProfileController::class, 'show']);
    Route::post('/account/profile', [AccountProfileController::class, 'store']);

    Route::post('/content/generate', [ContentController::class, 'generate']);
    Route::post('/content/drafts', [ContentController::class, 'saveDraft']);
    Route::put('/content/drafts/{post}', [ContentController::class, 'updateDraft']);
    Route::delete('/content/drafts/{post}', [ContentController::class, 'destroy']);
    Route::get('/content/posts', [ContentController::class, 'index']);

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

    Route::get('/admin/access', fn (\Illuminate\Http\Request $request) => response()->json([
        'is_admin' => (bool) $request->user()->is_admin,
    ]));

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview']);
        Route::get('/customers', [AdminController::class, 'customers']);
        Route::get('/customers/{user}', [AdminController::class, 'showCustomer']);
        Route::patch('/customers/{user}', [AdminController::class, 'updateCustomer']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
        Route::get('/decisions', [AdminController::class, 'decisions']);
        Route::get('/posts', [AdminController::class, 'posts']);
        Route::get('/operations', [AdminController::class, 'operations']);
        Route::get('/system', [AdminController::class, 'system']);
        Route::post('/trends/seed', [AdminController::class, 'seedTrends']);
        Route::post('/seed-demo', [AdminController::class, 'seedDemo']);
        Route::post('/evaluate-all', [AdminController::class, 'evaluateAll']);
        Route::post('/reset-safety', [AdminController::class, 'resetSafety']);
    });
});
