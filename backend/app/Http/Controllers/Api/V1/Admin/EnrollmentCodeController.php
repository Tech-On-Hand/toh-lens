<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Classroom;
use App\Models\DeviceEnrollmentCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnrollmentCodeController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['classroom_id' => ['required', 'exists:classrooms,id']]);
        $classroom = Classroom::findOrFail($data['classroom_id']);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($classroom->school_id), 403);

        do {
            $code = strtoupper(Str::random(4).'-'.Str::random(4));
            $hash = hash('sha256', $code);
        } while (DeviceEnrollmentCode::query()->where('code_hash', $hash)->exists());

        $enrollment = DeviceEnrollmentCode::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'created_by' => $user->id,
            'code_hash' => $hash,
            'expires_at' => now()->addMinutes(30),
        ]);

        return $this->success([
            'code' => $code,
            'expires_at' => $enrollment->expires_at->toIso8601String(),
            'classroom_id' => $classroom->id,
        ], 201);
    }
}
