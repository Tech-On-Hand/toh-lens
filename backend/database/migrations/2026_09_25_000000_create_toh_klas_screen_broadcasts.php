<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One device's screen shown live to every other kiosk in the classroom.
        // Direct kiosk-to-kiosk connections (see screen_broadcast_targets), not
        // relayed through the teacher or backend — this row just coordinates who
        // is currently broadcasting to whom. Independent of a regular teacher
        // watch session on the same device: separate capture+encode pipeline.
        Schema::create('screen_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_computer_id')->constrained('computers')->cascadeOnDelete();
            $table->foreignId('started_by')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', ['ended', 'source_offline', 'source_revoked'])->nullable();
            $table->timestamps();

            // Finds "the current broadcast for this device" and "is this classroom
            // currently broadcasting" (only one active broadcast per classroom at a
            // time, enforced in the controller, not here).
            $table->index(['source_computer_id', 'status']);
            $table->index(['classroom_id', 'status']);
        });

        // One row per receiving kiosk that has joined — the direction is reversed
        // from screen_sessions: the target (receiving kiosk) is the offerer here,
        // same role a teacher plays when watching, and the source device answers,
        // same role a watched device plays. Everything else about the shape
        // (candidates, polling, liveness-via-poll) mirrors screen_sessions on
        // purpose, since it is structurally the same kind of connection.
        Schema::create('screen_broadcast_targets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('screen_broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_computer_id')->constrained('computers')->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'ended'])->default('pending');
            $table->text('offer_sdp');
            $table->text('answer_sdp')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', ['ended', 'expired', 'target_lost', 'source_offline', 'source_revoked'])->nullable();
            $table->timestamps();

            $table->index(['screen_broadcast_id', 'status']);
            $table->index(['target_computer_id', 'status']);
        });

        // Named screen_broadcast_candidates (not ...target_candidates) with a
        // short target_id column: the natural name pushed the FK constraint's
        // auto-generated identifier past MySQL's 64-character limit.
        Schema::create('screen_broadcast_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained('screen_broadcast_targets')->cascadeOnDelete();
            $table->enum('source', ['source', 'target']);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_broadcast_candidates');
        Schema::dropIfExists('screen_broadcast_targets');
        Schema::dropIfExists('screen_broadcasts');
    }
};
