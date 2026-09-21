<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['administrator']);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::create('school_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['administrator', 'teacher']);
            $table->timestamps();
            $table->unique(['school_id', 'user_id']);
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->timestamps();
            $table->unique(['school_id', 'name']);
        });

        Schema::create('classroom_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['primary_teacher', 'assistant_teacher', 'observer']);
            $table->timestamps();
            $table->unique(['classroom_id', 'user_id']);
        });

        Schema::table('computers', function (Blueprint $table) {
            $table->uuid('device_uuid')->nullable()->unique()->after('id');
            $table->foreignId('classroom_id')->nullable()->after('school_id')->constrained()->nullOnDelete();
            $table->string('hostname')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('agent_version')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->enum('presence_status', ['online', 'offline'])->default('offline')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->unsignedBigInteger('configuration_version')->default(1);
        });

        Schema::table('login_sessions', function (Blueprint $table) {
            $table->foreignId('classroom_id')->nullable()->after('computer_id')->constrained()->nullOnDelete();
            $table->foreignId('class_id')->nullable()->after('classroom_id')->constrained('classes')->nullOnDelete();
            $table->enum('status', ['active', 'ended', 'expired', 'unknown'])->default('unknown');
            $table->string('authentication_method')->default('admission_number');
        });

        Schema::create('device_enrollment_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->enum('role', ['organization_administrator', 'school_administrator', 'teacher']);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'Tech On Hand',
            'slug' => 'tech-on-hand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('schools')->update(['organization_id' => $organizationId]);

        foreach (DB::table('users')->pluck('id') as $userId) {
            DB::table('organization_user')->insert([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'role' => 'administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('schools')->get(['id', 'name']) as $school) {
            $classroomId = DB::table('classrooms')->insertGetId([
                'school_id' => $school->id,
                'uuid' => (string) Str::uuid(),
                'name' => 'Computer Lab',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('computers')->where('school_id', $school->id)->update(['classroom_id' => $classroomId]);
        }

        foreach (DB::table('computers')->whereNull('device_uuid')->pluck('id') as $computerId) {
            DB::table('computers')->where('id', $computerId)->update(['device_uuid' => (string) Str::uuid()]);
        }

        DB::table('login_sessions')->whereNull('logout_time')->update(['status' => 'active']);
        DB::table('login_sessions')->whereNotNull('logout_time')->update(['status' => 'ended']);
        DB::statement('UPDATE login_sessions SET classroom_id = (SELECT classroom_id FROM computers WHERE computers.id = login_sessions.computer_id)');
        DB::statement('UPDATE login_sessions SET class_id = (SELECT class_id FROM students WHERE students.id = login_sessions.student_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
        Schema::dropIfExists('device_enrollment_codes');

        Schema::table('login_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropConstrainedForeignId('class_id');
            $table->dropColumn(['status', 'authentication_method']);
        });

        Schema::table('computers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropColumn([
                'device_uuid', 'hostname', 'operating_system', 'agent_version', 'enrolled_at',
                'last_seen_at', 'presence_status', 'revoked_at', 'configuration_version',
            ]);
        });

        Schema::dropIfExists('classroom_user');
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('school_user');
        Schema::table('schools', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
