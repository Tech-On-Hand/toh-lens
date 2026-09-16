<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RosterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Computer $computer */
        $computer = $request->user();

        $students = Student::query()
            ->where('school_id', $computer->school_id)
            ->where('is_active', true)
            ->get(['id', 'admission_number', 'full_name', 'class_id']);

        return response()->json([
            'school_id' => $computer->school_id,
            'generated_at' => now()->toIso8601String(),
            'students' => $students,
        ]);
    }
}
