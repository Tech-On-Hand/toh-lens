<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\StudentSessionChanged;
use App\Http\Requests\Api\SyncSessionsRequest;
use App\Models\Computer;
use App\Models\LoginSession;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class DeviceSessionController extends ApiController
{
    public function store(SyncSessionsRequest $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $results = [];

        foreach ($request->validated('sessions') as $row) {
            $student = Student::query()
                ->where('school_id', $device->school_id)
                ->where('admission_number', $row['admission_number'])
                ->first();

            $session = LoginSession::updateOrCreate(
                ['uuid' => $row['uuid']],
                [
                    'school_id' => $device->school_id,
                    'computer_id' => $device->id,
                    'classroom_id' => $device->classroom_id,
                    'class_id' => $student?->class_id,
                    'student_id' => $student?->id,
                    'admission_number' => $row['admission_number'],
                    'login_time' => $row['login_time'],
                    'logout_time' => $row['logout_time'] ?? null,
                    'status' => isset($row['logout_time']) ? 'ended' : 'active',
                    'authentication_method' => 'admission_number',
                ]
            );

            if ($session->wasRecentlyCreated || $session->wasChanged('logout_time')) {
                StudentSessionChanged::dispatch($session);
            }

            $results[] = ['uuid' => $row['uuid'], 'status' => 'synced'];
        }

        return $this->success(['results' => $results]);
    }
}
