<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DevicePresenceChanged;
use App\Models\Computer;
use App\Models\DeviceActivityDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends ApiController
{
    public function configuration(Request $request): JsonResponse
    {
        return $this->success($this->configurationData($request->user()));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hostname' => ['nullable', 'string', 'max:255'],
            'operating_system' => ['nullable', 'string', 'max:255'],
            'agent_version' => ['required', 'string', 'max:50'],
            'health' => ['nullable', 'array'],
            'health.screen_capture_supported' => ['nullable', 'boolean'],
            'health.unsynced_sessions' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        /** @var Computer $device */
        $device = $request->user();
        $wasOnline = $device->presence_status === 'online';
        $lastSeen = $device->last_seen_at;
        $metadataChanged = $device->hostname !== ($data['hostname'] ?? $device->hostname)
            || $device->operating_system !== ($data['operating_system'] ?? $device->operating_system)
            || $device->agent_version !== $data['agent_version'];

        $device->update([
            'hostname' => $data['hostname'] ?? $device->hostname,
            'operating_system' => $data['operating_system'] ?? $device->operating_system,
            'agent_version' => $data['agent_version'],
            'health' => isset($data['health']) ? [
                'screen_capture_supported' => $data['health']['screen_capture_supported'] ?? null,
                'unsynced_sessions' => $data['health']['unsynced_sessions'] ?? null,
            ] : $device->health,
            'last_seen_at' => now(),
            'presence_status' => 'online',
        ]);

        // One row per computer per day, written only the first time it is seen that day.
        if (! $lastSeen || ! $lastSeen->isToday()) {
            DeviceActivityDay::query()->firstOrCreate(['computer_id' => $device->id, 'day' => now()->toDateString()]);
        }

        if (! $wasOnline || $metadataChanged) {
            DevicePresenceChanged::dispatch($device->fresh(), 'online');
        }

        return $this->success([
            'server_time' => now()->toIso8601String(),
            'configuration' => $this->configurationData($device->fresh()),
        ]);
    }

    private function configurationData(Computer $device): array
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
