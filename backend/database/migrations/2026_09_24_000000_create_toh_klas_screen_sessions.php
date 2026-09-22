<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One teacher watching one device's screen. Only one active (pending/active)
        // row per device is allowed at a time — enforced in the controller, not here.
        Schema::create('screen_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'ended'])->default('pending');
            $table->enum('quality', ['thumb', 'full'])->default('thumb');
            $table->text('offer_sdp');
            $table->text('answer_sdp')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', ['ended', 'expired', 'device_offline', 'device_revoked'])->nullable();
            $table->timestamps();

            // Finds "the current session for this device" and enforces one-at-a-time.
            $table->index(['computer_id', 'status']);
        });

        // Append-only, like audit_logs: ICE candidates from either side, so a
        // poll-based device can fetch what it has not seen yet.
        Schema::create('screen_session_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screen_session_id')->constrained()->cascadeOnDelete();
            $table->enum('source', ['device', 'viewer']);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['screen_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_session_candidates');
        Schema::dropIfExists('screen_sessions');
    }
};
