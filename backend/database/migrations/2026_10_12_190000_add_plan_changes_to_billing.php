<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->timestamp('current_period_starts_at')->nullable()->after('activated_at');
            $table->boolean('renews')->default(true)->after('current_period_ends_at');
            $table->unsignedInteger('first_charge_cents')->nullable()->after('total_cents');
            $table->unsignedInteger('credit_cents')->default(0)->after('first_charge_cents');
            $table->unsignedBigInteger('replaces_subscription_id')->nullable()->after('credit_cents');
            $table->json('next_plan')->nullable()->after('renews');
            $table->timestamp('canceled_at')->nullable()->after('next_plan');
            $table->string('last_renewal_ref')->nullable()->after('gateway_event_id');
        });

        DB::table('billing_subscriptions')
            ->whereNotNull('activated_at')
            ->update(['current_period_starts_at' => DB::raw('activated_at')]);

        Schema::table('platform_prices', function (Blueprint $table) {
            $table->unsignedInteger('yearly_price_cents')->nullable()->after('list_price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('platform_prices', function (Blueprint $table) {
            $table->dropColumn('yearly_price_cents');
        });

        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'current_period_starts_at',
                'renews',
                'first_charge_cents',
                'credit_cents',
                'replaces_subscription_id',
                'next_plan',
                'canceled_at',
                'last_renewal_ref',
            ]);
        });
    }
};
