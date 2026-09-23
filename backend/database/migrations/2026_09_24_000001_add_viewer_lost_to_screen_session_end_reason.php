<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screen_sessions', function (Blueprint $table) {
            $table->enum('end_reason', ['ended', 'expired', 'device_offline', 'device_revoked', 'viewer_lost'])
                ->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('screen_sessions', function (Blueprint $table) {
            $table->enum('end_reason', ['ended', 'expired', 'device_offline', 'device_revoked'])
                ->nullable()->change();
        });
    }
};
