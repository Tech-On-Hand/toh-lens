<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Computer;
use App\Models\DeviceEnrollmentCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceEnrollmentController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'device_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255'],
            'operating_system' => ['required', 'string', 'max:255'],
            'agent_version' => ['required', 'string', 'max:50'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $enrollment = DeviceEnrollmentCode::query()
                ->where('code_hash', hash('sha256', strtoupper(trim($data['code']))))
                ->lockForUpdate()
                ->first();

            if (! $enrollment || $enrollment->used_at || $enrollment->expires_at->isPast()) {
                return null;
            }

            $device = Computer::query()->firstOrNew(['device_uuid' => $data['device_uuid']]);

            if ($device->exists && ($device->revoked_at || $device->school_id !== $enrollment->school_id)) {
                return null;
            }

            $device->fill([
                'school_id' => $enrollment->school_id,
                'classroom_id' => $enrollment->classroom_id,
                'name' => $data['name'],
                'hostname' => $data['hostname'],
                'operating_system' => $data['operating_system'],
                'agent_version' => $data['agent_version'],
                'role' => 'student',
                'enrolled_at' => now(),
                'last_seen_at' => now(),
                'presence_status' => 'online',
            ])->save();
            // `save()` on a new row doesn't pull the DB-level default for
            // configuration_version back into memory, so this stays null in the
            // response below (a required field on the agent's side) without this.
            $device->refresh();

            $device->tokens()->delete();
            $token = $device->createToken('student-agent', ['device'])->plainTextToken;
            $enrollment->update(['used_at' => now()]);

            return [$device, $token];
        });

        if (! $result) {
            return $this->error('ENROLLMENT_CODE_INVALID', 'The enrollment code is invalid, expired, or already used.', 422);
        }

        [$device, $token] = $result;

        return $this->success([
            'token' => $token,
            'device' => $this->deviceConfiguration($device),
        ], 201);
    }

    private function deviceConfiguration(Computer $device): array
    {
        return [
            'id' => $device->id,
            'device_uuid' => $device->device_uuid,
            'school_id' => $device->school_id,
            'classroom_id' => $device->classroom_id,
            'name' => $device->name,
            'configuration_version' => $device->configuration_version,
        ];
    }
}
