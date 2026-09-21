<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Current tab state per device, replaced wholesale by each snapshot.
        Schema::create('browser_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('login_session_id')->nullable()->constrained('login_sessions')->nullOnDelete();
            $table->string('browser', 20);
            $table->unsignedBigInteger('browser_tab_id');
            $table->unsignedBigInteger('window_id')->nullable();
            $table->text('url')->nullable();
            $table->string('title', 500)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('observed_at')->index();
            $table->timestamps();

            $table->unique(['computer_id', 'browser', 'browser_tab_id']);
        });

        // Append-only navigation history, idempotent on the client-generated uuid.
        Schema::create('browser_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('login_session_id')->nullable()->constrained('login_sessions')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('browser', 20);
            $table->string('event_type', 20);
            $table->text('url')->nullable();
            $table->string('domain')->nullable()->index();
            $table->string('title', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['computer_id', 'occurred_at']);
            $table->index(['login_session_id', 'occurred_at']);
        });

        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('login_session_id')->nullable()->constrained('login_sessions')->nullOnDelete();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50);
            $table->json('payload');
            $table->enum('status', ['pending', 'delivered', 'completed', 'failed', 'expired'])->default('pending');
            $table->json('result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['computer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
        Schema::dropIfExists('browser_activities');
        Schema::dropIfExists('browser_tabs');
    }
};
