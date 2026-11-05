<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_prices')) {
            return;
        }

        $now = now();
        $hasYearly = Schema::hasColumn('platform_prices', 'yearly_price_cents');

        foreach (['starter' => 2700, 'pro' => 4600, 'agency' => 10800] as $tier => $cents) {
            $values = [
                'list_price_cents' => $cents,
                'updated_at' => $now,
            ];
            if ($hasYearly) {
                $values['yearly_price_cents'] = $cents * (int) config('billing.yearly_months_charged', 10);
            }

            DB::table('platform_prices')
                ->where('platform', 'x')
                ->where('tier', $tier)
                ->update($values);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_prices')) {
            return;
        }

        $now = now();
        $hasYearly = Schema::hasColumn('platform_prices', 'yearly_price_cents');

        foreach (['starter' => 2700, 'pro' => 4600, 'agency' => 10800] as $tier => $cents) {
            $values = [
                'list_price_cents' => $cents,
                'updated_at' => $now,
            ];
            if ($hasYearly) {
                $values['yearly_price_cents'] = $cents * (int) config('billing.yearly_months_charged', 10);
            }

            DB::table('platform_prices')
                ->where('platform', 'x')
                ->where('tier', $tier)
                ->update($values);
        }
    }
};
