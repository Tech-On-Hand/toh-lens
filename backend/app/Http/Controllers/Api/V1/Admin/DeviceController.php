<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\DeviceConfigurationUpdated;
use App\Events\DeviceRevoked;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends ApiController
{
    public function update(Request $request, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($device->school_id), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'classroom_id' => ['sometimes', 'required', 'exists:classrooms,id'],
        ]);

        if (isset($data['classroom_id'])) {
            $classroom = Classroom::findOrFail($data['classroom_id']);
            abort_unless($classroom->school_id === $device->school_id, 422);
        }

        $device->fill($data);
        $device->configuration_version++;
        $device->save();
        Audit::record('device.updated', $user, null, $device, ['changes' => array_keys($data)]);
        DeviceConfigurationUpdated::dispatch($device);

        return $this->success($device);
    }

    public function revoke(Request $request, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator($device->school_id), 403);

        Audit::record('device.revoked', $user, null, $device);
        DeviceRevoked::dispatch($device);
        $device->update(['revoked_at' => now(), 'presence_status' => 'offline']);
        $device->endCurrentScreenSession('device_revoked');
        $device->tokens()->delete();

        return $this->success(['revoked' => true]);
    }
}
