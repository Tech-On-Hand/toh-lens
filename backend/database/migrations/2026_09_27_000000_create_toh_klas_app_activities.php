<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which desktop application a student had in front, as intervals. Only the
        // process name is stored (WINWORD.EXE), never a window title, since titles
        // carry document names and other personal content. The kiosk chooses the
        // uuid and may re-send an interval with a later ended_at while it is still
        // open, so the server upserts on it. An idle interval (no keyboard or mouse
        // for a while) is kept separate rather than credited to the last app.
        Schema::create('app_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('login_session_id')->constrained('login_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('process', 100);
            $table->boolean('is_idle')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->timestamps();

            $table->index(['login_session_id', 'started_at']);
            $table->index(['classroom_id', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_activities');
    }
};
