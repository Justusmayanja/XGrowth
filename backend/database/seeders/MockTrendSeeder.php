<?php

namespace Database\Seeders;

use App\Services\TrendEngine\TrendEngineService;
use Illuminate\Database\Seeder;

class MockTrendSeeder extends Seeder
{
    public function run(): void
    {
        app(TrendEngineService::class)->seedMockTrends();
    }
}
