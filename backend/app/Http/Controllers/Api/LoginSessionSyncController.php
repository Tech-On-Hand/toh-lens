<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncSessionsRequest;
use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class LoginSessionSyncController extends Controller
{
    public function store(SyncSessionsRequest $request): JsonResponse
    {
        /** @var Computer $computer */
        $computer = $request->user();

        $results = [];

        foreach ($request->validated('sessions') as $row) {
            $studentId = Student::query()
                ->where('school_id', $computer->school_id)
                ->where('admission_number', $row['admission_number'])
                ->value('id');

            LoginSession::updateOrCreate(
                ['uuid' => $row['uuid']],
                [
                    'school_id' => $computer->school_id,
                    'computer_id' => $computer->id,
                    'student_id' => $studentId,
                    'admission_number' => $row['admission_number'],
                    'login_time' => $row['login_time'],
                    'logout_time' => $row['logout_time'] ?? null,
                ]
            );

            $results[] = ['uuid' => $row['uuid'], 'status' => 'synced'];
        }

        return response()->json(['results' => $results]);
    }
}
