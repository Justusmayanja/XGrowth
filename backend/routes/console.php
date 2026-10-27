<?php

use App\Jobs\FetchGlobalTrendsJob;
use App\Jobs\SafetyResetJob;
use App\Models\User;
use App\Services\Admin\AdminDemoDataService;
use App\Services\Admin\PlatformSettingsService;
use App\Services\Billing\BillingLifecycle;
use App\Services\Billing\TrialService;
use App\Services\GrowthAgent\GrowthAgentService;
use App\Services\GrowthAgent\SafetyEngineService;
use App\Services\TikTok\TikTokEngineService;
use App\Services\TikTok\TikTokOAuthService;
use App\Services\TrendEngine\TrendEngineService;
use App\Services\YouTube\YouTubeEngineService;
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
    $result = app(AdminDemoDataService::class)->seed($this->option('force'));
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
// The loop only looks. A loud moment can post within minutes; a quiet day does not post on this timer.
$evalInterval = 5;

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

Artisan::command('youtube:run', function () {
    $count = app(YouTubeEngineService::class)->runAll();
    $this->info("Ran the YouTube engine for {$count} account(s).");
})->purpose('Find the next YouTube video, script it, and queue it');

Schedule::call(fn () => app(YouTubeEngineService::class)->runAll())
    ->hourly()
    ->name('youtube-engine')
    ->withoutOverlapping(15);

Artisan::command('tiktok:run {--user=}', function () {
    $engine = app(TikTokEngineService::class);
    $userId = $this->option('user');

    if ($userId !== null && $userId !== '') {
        $user = User::query()->find($userId);
        if (! $user) {
            $this->error('No user with that id.');

            return 1;
        }

        try {
            $result = $engine->run($user);
        } catch (Throwable $e) {
            $this->error('Failed: '.$e::class);

            return 1;
        }

        $summary = [
            'created' => ($result['outcome'] ?? '') === 'created' ? 1 : 0,
            'skipped' => isset($result['reason']) ? [$result['reason'] => 1] : [],
            'failed' => 0,
        ];
    } else {
        $summary = $engine->runAll();
    }

    $rows = [['created', (string) $summary['created']]];
    foreach ($summary['skipped'] as $reason => $count) {
        $rows[] = ['skipped: '.$reason, (string) $count];
    }
    $rows[] = ['failed', (string) $summary['failed']];
    $this->table(['Outcome', 'Count'], $rows);

    return 0;
})->purpose('Prepare the next TikTok clip for eligible accounts');

Schedule::call(fn () => app(TikTokEngineService::class)->runAll())
    ->hourly()
    ->name('tiktok-engine')
    ->withoutOverlapping(15);

Artisan::command('tiktok:refresh-tokens', function () {
    $summary = app(TikTokOAuthService::class)->refreshExpiring();
    $this->info("Refreshed {$summary['refreshed']} TikTok token(s). Failed {$summary['failed']}.");
})->purpose('Refresh TikTok access tokens that expire within a day');

Schedule::call(fn () => app(TikTokOAuthService::class)->refreshExpiring())
    ->daily()
    ->name('tiktok-token-refresh')
    ->withoutOverlapping(15);

// Run in the scheduler process. A queued job never posts when no queue worker is running.
Schedule::call(fn () => app(GrowthAgentService::class)->evaluateAllUsers())
    ->cron("*/{$evalInterval} * * * *")
    ->name('growth-evaluation')
    ->withoutOverlapping(15);
