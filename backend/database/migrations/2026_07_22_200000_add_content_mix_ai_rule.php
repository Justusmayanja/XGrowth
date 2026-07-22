<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('ai_rules')->where('key', 'content_mix')->exists()) {
            return;
        }

        $now = now();

        DB::table('ai_rules')->insert([
            'key' => 'content_mix',
            'label' => 'Content mix (trend vs original)',
            'description' => 'Balance trend-reactive posts with original evergreen content. Edit the percentage below.',
            'rules' => implode("\n", [
                'Trend-based content must be at most 70% of generated/posted content.',
                'At least 30% must be original evergreen content — insights, questions, opinions, and lessons not tied to current trends.',
                'When recent trend-based share is at or above 70%, prioritize original content until the mix recovers.',
                'Original content should still match the user niche, tone, and goals.',
                'Trend content is for timely moments; original content builds long-term authority.',
            ]),
            'is_active' => true,
            'sort_order' => 6,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('ai_rules')->where('key', 'content_mix')->delete();
    }
};
