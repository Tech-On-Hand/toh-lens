<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'action' => ['nullable', 'string', 'max:60'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);

        $logs = AuditLog::query()
            ->where('school_id', $data['school_id'])
            ->when($data['classroom_id'] ?? null, fn ($query, $id) => $query->where('classroom_id', $id))
            ->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($data['before_id'] ?? null, fn ($query, $id) => $query->where('id', '<', $id))
            ->orderByDesc('id')
            ->limit($data['limit'] ?? 50)
            ->get();

        $actors = User::query()->whereIn('id', $logs->pluck('actor_id')->filter())->pluck('name', 'id');

        return $this->success($logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => $log->actor_id ? ['id' => $log->actor_id, 'name' => $actors[$log->actor_id] ?? null] : null,
            'classroom_id' => $log->classroom_id,
            'device_id' => $log->computer_id,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at->toIso8601String(),
        ]));
    }
}
