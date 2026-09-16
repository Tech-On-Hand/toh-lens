<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->string('admission_number');
            $table->string('full_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'admission_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
