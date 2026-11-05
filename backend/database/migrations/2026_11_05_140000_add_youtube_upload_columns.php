<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->timestamp('posted_at')->nullable();
            $table->string('posted_video_id', 32)->nullable();
            $table->string('posted_privacy', 16)->nullable();
        });

        Schema::create('youtube_insert_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('youtube_video_id')->constrained('youtube_videos')->cascadeOnDelete();
            $table->date('reserved_on');
            $table->timestamps();

            $table->index('reserved_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_insert_attempts');

        Schema::table('youtube_videos', function (Blueprint $table) {
            $table->dropColumn(['posted_at', 'posted_video_id', 'posted_privacy']);
        });
    }
};
