<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('premise');
            $table->json('characters');
            $table->json('outline');
            $table->unsignedSmallInteger('next_episode')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('youtube_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('youtube_series_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 16);
            $table->string('format', 32)->nullable();
            $table->string('status', 32);
            $table->string('topic')->nullable();
            $table->string('title')->nullable();
            $table->text('script')->nullable();
            $table->text('description')->nullable();
            $table->json('package')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedSmallInteger('episode_number')->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('average_view_percent', 5, 2)->nullable();
            $table->text('learning_note')->nullable();
            $table->timestamp('learned_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_videos');
        Schema::dropIfExists('youtube_series');
    }
};
