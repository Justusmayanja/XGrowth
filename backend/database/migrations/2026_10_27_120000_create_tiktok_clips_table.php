<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('drafted');
            $table->string('topic')->nullable();
            $table->text('hook')->nullable();
            $table->text('script')->nullable();
            $table->text('caption')->nullable();
            $table->timestampTz('suggested_for')->nullable();
            $table->jsonb('package')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedBigInteger('views')->nullable();
            $table->decimal('completion_percent', 5, 2)->nullable();
            $table->unsignedInteger('follows')->nullable();
            $table->text('learning_note')->nullable();
            $table->timestampTz('learned_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index('platform_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_clips');
    }
};
