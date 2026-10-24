<?php

use App\Jobs\FetchGlobalTrendsJob;
use App\Jobs\SafetyResetJob;
use App\Models\User;
use App\Services\Admin\PlatformSettingsService;
use App\Services\Billing\BillingLifecycle;
use App\Services\Billing\TrialService;
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
    $trends = app(TrendEngineService::class)->fetchGlobalTrends(force: true);
    $this->info('Refreshed '.count($trends).' trend(s).');
})->purpose('Refresh the shared trend catalog from X');

Artisan::command('xpulse:reset-safety', function () {
    $deleted = app(SafetyEngineService::class)->resetDailyBudgets();
    $this->info("Removed {$deleted} expired safety budget row(s).");
})->purpose('Clean up expired daily rate-limit budget rows');

Artisan::command('xpulse:seed-demo {--force : Re-seed demo users even if they already exist}', function () {
    $result = app(\App\Services\Admin\AdminDemoDataService::class)->seed($this->option('force'));
    $this->info("Seeded {$result['users']} user(s), {$result['trends']} trend(s), {$result['posts']} post(s), {$result['decisions']} decision(s).");
})->purpose('Seed demo users, posts, and decisions for admin development');

Artisan::command('xpulse:make-admin {email : User email address}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error("No user found with email: {$email}");

        return 1;
    }

    $user->update(['is_admin' => true]);
    $this->info("{$user->name} ({$user->email}) is now an admin.");

    return 0;
})->purpose('Grant admin access to a user by email');

Artisan::command('xpulse:expire-trials', function () {
    $count = app(TrialService::class)->expireDue();
    $this->info("Ended {$count} free trial(s).");
})->purpose('End free trials whose trial period is over');

Artisan::command('xpulse:billing-sweep', function () {
    $result = app(BillingLifecycle::class)->sweep();
    $this->info("Ended {$result['ended']} plan(s), marked {$result['past_due']} past due.");
})->purpose('End plans whose paid period ran out and flag unconfirmed renewals');

Schedule::call(fn () => app(TrialService::class)->expireDue())
    ->hourly()
    ->name('expire-trials');

Schedule::call(fn () => app(BillingLifecycle::class)->sweep())
    ->hourly()
    ->name('billing-sweep');

$settings = app(PlatformSettingsService::class);
$trendFetchTimes = $settings->getFetchTimes();
$evalInterval = min(59, max(30, $settings->getInt('growth_evaluation_interval_minutes', 30)));

if (config('queue.default') === 'redis') {
    foreach ($trendFetchTimes as $time) {
        Schedule::job(new FetchGlobalTrendsJob)->dailyAt($time);
    }
    Schedule::job(new SafetyResetJob)->dailyAt('00:05');
} else {
    foreach ($trendFetchTimes as $time) {
        Schedule::call(fn () => app(TrendEngineService::class)->fetchGlobalTrends())
            ->dailyAt($time)
            ->name("fetch-trends-{$time}");
    }
    Schedule::call(fn () => app(SafetyEngineService::class)->resetDailyBudgets())->dailyAt('00:05');
}

// Run in the scheduler process. A queued job never posts when no queue worker is running.
Schedule::call(fn () => app(GrowthAgentService::class)->evaluateAllUsers())
    ->cron("*/{$evalInterval} * * * *")
    ->name('growth-evaluation')
    ->withoutOverlapping(15);
