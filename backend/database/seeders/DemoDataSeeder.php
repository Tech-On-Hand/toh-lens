<?php

namespace Database\Seeders;

use App\Models\Computer;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed a single demo school with a class, students, and one kiosk computer.
     */
    public function run(): void
    {
        $school = School::create(['name' => 'Demo Primary School']);

        $class = SchoolClass::create([
            'school_id' => $school->id,
            'name' => 'Grade 4 Blue',
        ]);

        $students = [
            ['admission_number' => '1001', 'full_name' => 'Amara Otieno'],
            ['admission_number' => '1002', 'full_name' => 'Brian Mwangi'],
            ['admission_number' => '1003', 'full_name' => 'Cynthia Wanjiru'],
            ['admission_number' => '1004', 'full_name' => 'David Kiptoo'],
            ['admission_number' => '1005', 'full_name' => 'Esther Achieng'],
        ];

        foreach ($students as $student) {
            Student::create([
                'school_id' => $school->id,
                'class_id' => $class->id,
                'admission_number' => $student['admission_number'],
                'full_name' => $student['full_name'],
            ]);
        }

        $computer = Computer::create([
            'school_id' => $school->id,
            'class_id' => $class->id,
            'name' => 'Lab PC 1',
            'role' => 'student',
        ]);

        $token = $computer->createToken('kiosk-token')->plainTextToken;

        $this->command->info("Seeded school #{$school->id} ({$school->name}) with computer #{$computer->id} ({$computer->name}).");
        $this->command->info('Computer API token:');
        $this->command->line($token);
        $this->command->warn('This token is shown only once. Store it securely.');
    }
}
