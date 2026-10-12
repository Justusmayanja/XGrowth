<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_profiles', function (Blueprint $table) {
            $table->string('purpose', 32)->nullable()->after('connected_account_id');
            $table->string('platforms', 64)->nullable()->after('purpose');
            $table->json('discovery')->nullable()->after('audience');
        });

        Schema::create('platform_setups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('goals')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'platform']);
        });

        $now = now();

        DB::table('account_profiles')
            ->whereNotNull('goal')
            ->orderBy('id')
            ->each(function (object $profile) use ($now) {
                DB::table('account_profiles')->where('id', $profile->id)->update(['platforms' => 'x']);

                DB::table('platform_setups')->insert([
                    'user_id' => $profile->user_id,
                    'platform' => 'x',
                    'goals' => $profile->goal,
                    'settings' => json_encode([
                        'automation_level' => (int) ($profile->automation_level ?? 1),
                        'mode' => $profile->mode ?? 'reactive',
                    ]),
                    'completed_at' => $profile->onboarding_completed ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_setups');

        Schema::table('account_profiles', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'platforms', 'discovery']);
        });
    }
};
