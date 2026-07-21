<?php

namespace App\Jobs;

use App\Services\GrowthAgent\SafetyEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SafetyResetJob implements ShouldQueue
{
    use Queueable;

    public function handle(SafetyEngineService $safetyEngine): void
    {
        $safetyEngine->resetDailyBudgets();
    }
}
