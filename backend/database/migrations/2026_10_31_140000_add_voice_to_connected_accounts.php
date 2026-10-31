<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->string('voice_niche')->nullable()->after('username');
            $table->text('voice_description')->nullable()->after('voice_niche');
            $table->string('voice_audience')->nullable()->after('voice_description');
        });
    }

    public function down(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropColumn(['voice_niche', 'voice_description', 'voice_audience']);
        });
    }
};
