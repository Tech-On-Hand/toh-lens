<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A conversation is one student's login session on one computer: a teacher
        // talks to whoever is signed in there right now, and the next student to sit
        // down starts with an empty thread rather than reading the last one's.
        // The uuid is chosen by whoever sends, so a retry after a dropped connection
        // is idempotent.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('login_session_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['to_student', 'to_teacher']);
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('body', 500);
            $table->timestamp('sent_at')->useCurrent();
            // to_student: delivered when the kiosk fetches it, read when the student opens the chat.
            // to_teacher: delivered on arrival, read when a teacher opens the thread.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['login_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
