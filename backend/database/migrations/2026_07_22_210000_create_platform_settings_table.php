<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            'trend_fetch_times' => '06:00,14:00,22:00',
            'trend_fetch_cooldown_hours' => '4',
            'growth_evaluation_interval_minutes' => '30',
            'growth_brain_enabled' => '1',
            'growth_brain_prefilter_limit' => '5',
            'growth_eval_cooldown_minutes' => '30',
            'growth_brain_max_output_tokens' => '400',
            'web_search_enabled' => '1',
            'openai_model' => 'gpt-5.4-mini',
            'content_mix_max_trend_percent' => '70',
            'content_mix_sample_size' => '10',
            'rules_post_threshold' => '82',
            'rules_engage_threshold' => '68',
            'safety_limit_post' => '25',
            'safety_limit_reply' => '50',
            'safety_limit_follow' => '10',
            'safety_limit_like' => '100',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('platform_settings')->insert([
                'key' => $key,
                'value' => $value,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
