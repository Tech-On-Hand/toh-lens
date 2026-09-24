<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the Student Agent last reported about itself on its heartbeat (whether
        // the browser extension is connected, how many sessions it has not managed to
        // sync). Replaced on every heartbeat, so it is only ever "as of last_seen_at".
        Schema::table('computers', function (Blueprint $table) {
            $table->json('health')->nullable()->after('agent_version');
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropColumn('health');
        });
    }
};
