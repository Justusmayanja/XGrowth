<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('ai_rules')->where('key', 'growth_decisions')->exists()) {
            return;
        }

        $now = now();

        DB::table('ai_rules')->insert([
            'key' => 'growth_decisions',
            'label' => 'Growth Brain decision rules',
            'description' => 'Rules for AI-powered POST / WAIT / ENGAGE decisions from trend signals.',
            'rules' => implode("\n", [
                'Be conservative on POST — only when timing and niche fit are strong.',
                'Prefer ENGAGE over POST when the trend is conversational but not publish-worthy.',
                'Never recommend POST if safety.post_left is 0.',
                'Factor competition: high competition needs a sharper angle in content_angle.',
                'For reactive mode, prioritize breaking/live trends; for proactive, prioritize evergreen angles.',
                'Keep reason under 2 sentences; content_angle under 120 characters.',
            ]),
            'is_active' => true,
            'sort_order' => 5,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('ai_rules')->where('key', 'growth_decisions')->delete();
    }
};
