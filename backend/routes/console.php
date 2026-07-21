<?php

use App\Jobs\EvaluateAllGrowthOpportunitiesJob;
use App\Jobs\FetchGlobalTrendsJob;
use App\Jobs\SafetyResetJob;
use App\Services\GrowthAgent\GrowthAgentService;
use App\Services\GrowthAgent\SafetyEngineService;
use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('xpulse:evaluate', function () {
    $count = app(GrowthAgentService::class)->evaluateAllUsers();
    $this->info("Evaluated {$count} user(s).");
})->purpose('Run growth opportunity evaluation for all onboarded users');

Artisan::command('xpulse:seed-trends', function () {
    $trends = app(TrendEngineService::class)->seedMockTrends();
    $this->info('Seeded '.count($trends).' trend(s).');
})->purpose('Seed mock trends for local development');

Artisan::command('xpulse:reset-safety', function () {
    $deleted = app(SafetyEngineService::class)->resetDailyBudgets();
    $this->info("Removed {$deleted} expired safety budget row(s).");
})->purpose('Clean up expired daily rate-limit budget rows');

// Scheduler: inline calls for SQLite/database queue; Redis jobs when queue worker is available
if (config('queue.default') === 'redis') {
    Schedule::job(new FetchGlobalTrendsJob)->everyFiveMinutes();
    Schedule::job(new EvaluateAllGrowthOpportunitiesJob)->everyTenMinutes();
    Schedule::job(new SafetyResetJob)->dailyAt('00:05');
} else {
    Schedule::call(fn () => app(TrendEngineService::class)->fetchGlobalTrends())->everyFiveMinutes();
    Schedule::call(fn () => app(GrowthAgentService::class)->evaluateAllUsers())->everyTenMinutes();
    Schedule::call(fn () => app(SafetyEngineService::class)->resetDailyBudgets())->dailyAt('00:05');
}
