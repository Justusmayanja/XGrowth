<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_profiles', function (Blueprint $table) {
            $table->boolean('engine_paused')->default(false)->after('max_silence_minutes');
            $table->boolean('auto_post_enabled')->default(true)->after('engine_paused');
            $table->boolean('auto_reply_enabled')->default(false)->after('auto_post_enabled');
            $table->boolean('auto_engage_enabled')->default(true)->after('auto_reply_enabled');
            $table->boolean('notify_post_results')->default(true)->after('auto_engage_enabled');
            $table->boolean('notify_draft_ready')->default(true)->after('notify_post_results');
            $table->boolean('notify_weekly_recap')->default(false)->after('notify_draft_ready');
            $table->boolean('notify_safety_alerts')->default(true)->after('notify_weekly_recap');
        });
    }

    public function down(): void
    {
        Schema::table('account_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'engine_paused',
                'auto_post_enabled',
                'auto_reply_enabled',
                'auto_engage_enabled',
                'notify_post_results',
                'notify_draft_ready',
                'notify_weekly_recap',
                'notify_safety_alerts',
            ]);
        });
    }
};
