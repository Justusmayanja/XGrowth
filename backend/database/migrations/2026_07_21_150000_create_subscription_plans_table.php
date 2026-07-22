<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->string('slug')->primary();
            $table->string('name');
            $table->string('price_display');
            $table->string('period')->default('/mo');
            $table->text('description')->nullable();
            $table->json('features');
            $table->string('cta')->default('Start now');
            $table->boolean('highlighted')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('subscription_plans')->insert([
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price_display' => '$12',
                'period' => '/mo',
                'description' => 'For creators getting serious',
                'features' => json_encode(['Content studio', 'Trend discovery', 'Draft library', 'Basic analytics']),
                'cta' => 'Start now',
                'highlighted' => false,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'creator',
                'name' => 'Creator',
                'price_display' => '$29',
                'period' => '/mo',
                'description' => 'Full growth engine',
                'features' => json_encode([
                    'Auto growth modes',
                    'Advanced analytics',
                    'Trend intelligence',
                    'Account protection',
                    'Reach insights',
                ]),
                'cta' => 'Start now',
                'highlighted' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'price_display' => '$59',
                'period' => '/mo',
                'description' => 'Multiple accounts',
                'features' => json_encode([
                    'Multiple accounts',
                    'Priority processing',
                    'Competitor insights',
                    'Full automation',
                    'Priority support',
                ]),
                'cta' => 'Start now',
                'highlighted' => false,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('users')->where('subscription_plan', 'free')->update(['subscription_plan' => 'starter']);
        DB::table('users')->where('subscription_plan', 'agency')->update(['subscription_plan' => 'pro']);
        DB::table('users')
            ->whereNotIn('subscription_plan', ['starter', 'creator', 'pro'])
            ->update(['subscription_plan' => 'starter']);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
