<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_prices', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 32);
            $table->string('tier', 32);
            $table->unsignedInteger('list_price_cents');
            $table->timestamps();

            $table->unique(['platform', 'tier']);
        });

        $now = now();

        foreach (config('billing.platforms') as $platformId => $platform) {
            foreach ($platform['tiers'] as $tierId => $tier) {
                DB::table('platform_prices')->insert([
                    'platform' => $platformId,
                    'tier' => $tierId,
                    'list_price_cents' => $tier['list_cents'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('interval', 16);
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents');
            $table->unsignedInteger('total_cents');
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->string('gateway_event_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('billing_subscription_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('tier', 32);
            $table->unsignedInteger('list_cents');
            $table->unsignedInteger('charged_cents');
            $table->unsignedInteger('discount_cents');
            $table->boolean('is_anchor')->default(false);
            $table->boolean('discount_limited')->default(false);
            $table->unsignedSmallInteger('accounts_included');
            $table->unsignedInteger('monthly_allowance');
            $table->string('allowance_unit', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_subscription_lines');
        Schema::dropIfExists('billing_subscriptions');
        Schema::dropIfExists('platform_prices');
    }
};
