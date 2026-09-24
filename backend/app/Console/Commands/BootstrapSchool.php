<?php

namespace App\Console\Commands;

use App\Models\Classroom;
use App\Models\Organization;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapSchool extends Command
{
    protected $signature = 'klas:bootstrap
        {organization : Organization name, e.g. "Tech On Hand"}
        {school : School name}
        {admin_email : Email of the school administrator to create or reuse}
        {--admin-name= : Name for a new administrator (default: the part of the email before the @)}
        {--classroom=* : A classroom to create (repeat for several)}';

    protected $description = 'Set up a school for a pilot: its organization, classrooms and first administrator. Safe to run again.';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('admin_email')));
        if (Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
            $this->components->error("\"{$email}\" is not a valid email address.");

            return self::FAILURE;
        }

        $organization = Organization::query()->firstOrCreate(
            ['slug' => Str::slug($this->argument('organization'))],
            ['name' => $this->argument('organization')],
        );
        $school = School::query()->firstOrCreate(['organization_id' => $organization->id, 'name' => $this->argument('school')]);

        $classrooms = [];
        foreach (array_filter(array_map('trim', $this->option('classroom'))) as $name) {
            $classrooms[] = Classroom::query()->firstOrCreate(['school_id' => $school->id, 'name' => $name], ['uuid' => (string) Str::uuid()]);
        }

        $password = null;
        $admin = User::query()->where('email', $email)->first();
        if (! $admin) {
            $password = Str::password(16, symbols: false);
            $admin = User::query()->create([
                'name' => $this->option('admin-name') ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $admin->forceFill(['email_verified_at' => now()])->save();
        }
        $school->users()->syncWithoutDetaching([$admin->id => ['role' => 'administrator']]);

        $this->components->info("Organization \"{$organization->name}\" (#{$organization->id}), school \"{$school->name}\" (#{$school->id}).");
        foreach ($classrooms as $classroom) {
            $this->line("  classroom #{$classroom->id}  {$classroom->name}");
        }
        if ($password !== null) {
            $this->newLine();
            $this->components->info("Created administrator {$admin->email}. Sign in at ".config('app.url').' with:');
            $this->line("  password: {$password}");
            $this->components->warn('This password is shown once. Change it after signing in.');
        } else {
            $this->components->info("Reusing the existing account {$admin->email} as school administrator.");
        }

        return self::SUCCESS;
    }
}
