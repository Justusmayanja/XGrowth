<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('yearly_price_display')->nullable()->after('period');
            $table->string('yearly_period')->default('/yr')->after('yearly_price_display');
        });

        DB::table('users')->where('subscription_plan', 'starter')->update(['subscription_plan' => 'creator']);
        DB::table('users')
            ->whereNotIn('subscription_plan', ['creator', 'pro', 'agency'])
            ->update(['subscription_plan' => 'creator']);

        DB::table('subscription_plans')->where('slug', 'starter')->delete();

        $now = now();

        DB::table('subscription_plans')->where('slug', 'creator')->update([
            'name' => 'Creator',
            'price_display' => '$29',
            'period' => '/mo',
            'yearly_price_display' => '$290',
            'yearly_period' => '/yr',
            'description' => 'Full growth engine for solo creators',
            'features' => json_encode([
                'Content studio',
                'Trend discovery',
                'Auto growth modes',
                'Advanced analytics',
                'Account protection',
            ]),
            'cta' => 'Start now',
            'highlighted' => true,
            'sort_order' => 1,
            'updated_at' => $now,
        ]);

        DB::table('subscription_plans')->where('slug', 'pro')->update([
            'name' => 'Pro',
            'price_display' => '$59',
            'period' => '/mo',
            'yearly_price_display' => '$590',
            'yearly_period' => '/yr',
            'description' => 'Multiple accounts and priority processing',
            'features' => json_encode([
                'Multiple accounts',
                'Priority processing',
                'Competitor insights',
                'Full automation',
                'Priority support',
            ]),
            'cta' => 'Start now',
            'highlighted' => false,
            'sort_order' => 2,
            'updated_at' => $now,
        ]);

        DB::table('subscription_plans')->updateOrInsert(
            ['slug' => 'agency'],
            [
                'name' => 'Agency',
                'price_display' => '$149',
                'period' => '/mo',
                'yearly_price_display' => '$1,490',
                'yearly_period' => '/yr',
                'description' => 'Teams and client accounts at scale',
                'features' => json_encode([
                    'Team seats',
                    'Many accounts',
                    'Advanced reporting',
                    'Client switcher',
                    'Custom limits',
                ]),
                'cta' => 'Contact us',
                'highlighted' => false,
                'sort_order' => 3,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('slug', 'agency')->delete();

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['yearly_price_display', 'yearly_period']);
        });
    }
};
