<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_clip_videos', function (Blueprint $table) {
            $table->string('preview_path')->nullable()->after('path');
            $table->string('poster_path')->nullable()->after('preview_path');
            $table->unsignedBigInteger('preview_bytes')->nullable()->after('poster_path');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_clip_videos', function (Blueprint $table) {
            $table->dropColumn(['preview_path', 'poster_path', 'preview_bytes']);
        });
    }
};
