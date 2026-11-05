<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_research', function (Blueprint $table) {
            $table->json('api_cache')->nullable();
            $table->date('quota_date')->nullable();
            $table->unsignedInteger('quota_used')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('youtube_research', function (Blueprint $table) {
            $table->dropColumn(['api_cache', 'quota_date', 'quota_used']);
        });
    }
};
