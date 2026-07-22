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
            $table->unsignedSmallInteger('daily_ai_generations')->default(30)->after('sort_order');
            $table->unsignedSmallInteger('daily_web_searches')->default(5)->after('daily_ai_generations');
        });

        DB::table('subscription_plans')->where('slug', 'creator')->update([
            'daily_ai_generations' => 30,
            'daily_web_searches' => 5,
        ]);

        DB::table('subscription_plans')->where('slug', 'pro')->update([
            'daily_ai_generations' => 100,
            'daily_web_searches' => 20,
        ]);

        DB::table('subscription_plans')->where('slug', 'agency')->update([
            'daily_ai_generations' => 500,
            'daily_web_searches' => 100,
        ]);

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedSmallInteger('web_search_calls')->default(0);
            $table->boolean('used_web_search')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['daily_ai_generations', 'daily_web_searches']);
        });
    }
};
