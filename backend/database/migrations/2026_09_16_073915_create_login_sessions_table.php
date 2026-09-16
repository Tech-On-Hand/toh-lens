<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('admission_number');
            $table->timestamp('login_time');
            $table->timestamp('logout_time')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'login_time']);
            $table->index('computer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_sessions');
    }
};
