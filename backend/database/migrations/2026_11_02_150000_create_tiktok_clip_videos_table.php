<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_clip_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clip_id')->unique()->constrained('tiktok_clips')->cascadeOnDelete();
            $table->string('source', 16)->default('user');
            $table->string('disk', 32)->default('tiktok_videos');
            $table->string('path')->nullable();
            $table->string('original_filename');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('codec', 32)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->boolean('ready')->default(false);
            $table->string('aspect_warning')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_clip_videos');
    }
};
