<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Computer;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceRosterController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $students = Student::query()
            ->where('school_id', $device->school_id)
            ->where('is_active', true)
            ->get(['id', 'admission_number', 'full_name', 'class_id']);

        return $this->success([
            'school_id' => $device->school_id,
            'generated_at' => now()->toIso8601String(),
            'students' => $students,
        ]);
    }
}
