<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform')->default('x');
            $table->string('platform_account_id')->nullable();
            $table->string('username')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'platform_account_id']);
        });

        Schema::create('account_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('goal')->nullable();
            $table->string('niche')->nullable();
            $table->string('custom_niche')->nullable();
            $table->text('description')->nullable();
            $table->string('tone')->default('professional');
            $table->string('audience')->nullable();
            $table->string('mode')->default('reactive');
            $table->unsignedTinyInteger('automation_level')->default(1);
            $table->unsignedSmallInteger('max_silence_minutes')->default(60);
            $table->boolean('onboarding_completed')->default(false);
            $table->timestamps();
        });

        Schema::create('trends', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('score', 5, 2)->default(0);
            $table->decimal('velocity', 5, 2)->default(0);
            $table->decimal('growth_rate', 8, 2)->default(0);
            $table->string('competition')->default('medium');
            $table->json('niche_tags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trend_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform_post_id')->nullable();
            $table->string('content_type')->default('tweet');
            $table->text('content');
            $table->string('status')->default('draft');
            $table->decimal('virality_predicted', 5, 2)->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('reposts')->default(0);
            $table->unsignedInteger('replies')->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision');
            $table->string('action_taken')->nullable();
            $table->decimal('opportunity_score', 5, 2)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('rate_limit_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type');
            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('daily_limit')->default(0);
            $table->date('budget_date');
            $table->timestamps();

            $table->unique(['connected_account_id', 'action_type', 'budget_date'], 'rate_limit_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('subscription_plan')->default('free')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('subscription_plan');
        });

        Schema::dropIfExists('rate_limit_budgets');
        Schema::dropIfExists('ai_decisions');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('trends');
        Schema::dropIfExists('account_profiles');
        Schema::dropIfExists('connected_accounts');
    }
};
