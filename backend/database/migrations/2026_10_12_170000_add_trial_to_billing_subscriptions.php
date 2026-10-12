<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->nullable()->after('checkout_url');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_days');
        });
    }

    public function down(): void
    {
        Schema::table('billing_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['trial_days', 'trial_ends_at']);
        });
    }
};
