<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per computer per day it connected at least once. The server used to
        // keep only the last heartbeat, so it could not say how many days a computer
        // was actually switched on; this is what lets the impact report compare the
        // days a computer was available with the days students used it. History starts
        // the day this shipped.
        Schema::create('device_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['computer_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_activity_days');
    }
};
