<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_clips', function (Blueprint $table) {
            $table->string('tiktok_publish_id')->nullable()->after('platform_post_id');
            $table->text('upload_url')->nullable()->after('tiktok_publish_id');
            $table->text('upload_error')->nullable()->after('upload_url');
            $table->unsignedTinyInteger('upload_progress')->nullable()->after('upload_error');
            $table->timestampTz('bytes_sent_at')->nullable()->after('upload_progress');
            $table->timestampTz('drafted_at')->nullable()->after('bytes_sent_at');
        });

        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->text('scopes')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_clips', function (Blueprint $table) {
            $table->dropColumn([
                'tiktok_publish_id',
                'upload_url',
                'upload_error',
                'upload_progress',
                'bytes_sent_at',
                'drafted_at',
            ]);
        });

        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropColumn('scopes');
        });
    }
};
