<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A class's grade ("Grade 4") and stream ("Blue") kept apart so reports can
     * group several streams into one grade. `name` stays the label shown and
     * matched everywhere; both new columns are optional so existing classes
     * keep working untouched.
     */
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->string('grade', 50)->nullable()->after('name');
            $table->string('stream', 50)->nullable()->after('grade');
            $table->index(['school_id', 'grade']);
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'grade']);
            $table->dropColumn(['grade', 'stream']);
        });
    }
};
