<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Classroom;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherClassroomController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $classrooms = Classroom::query()
            ->with('school:id,name,organization_id')
            ->where(function ($query) use ($user) {
                $organizationIds = $user->organizations()->wherePivot('role', 'administrator')->pluck('organizations.id');
                $schoolIds = $user->schools()->wherePivot('role', 'administrator')->pluck('schools.id');
                $classroomIds = $user->classrooms()->pluck('classrooms.id');

                $query->whereIn('schools.organization_id', $organizationIds)
                    ->orWhereIn('classrooms.school_id', $schoolIds)
                    ->orWhereIn('classrooms.id', $classroomIds);
            })
            ->join('schools', 'schools.id', '=', 'classrooms.school_id')
            ->select('classrooms.*')
            ->orderBy('classrooms.name')
            ->get();

        return $this->success($classrooms->map(fn (Classroom $classroom) => [
            'id' => $classroom->id,
            'uuid' => $classroom->uuid,
            'name' => $classroom->name,
            'school' => ['id' => $classroom->school->id, 'name' => $classroom->school->name],
        ]));
    }

    public function devices(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $devices = Computer::query()
            ->where('classroom_id', $classroom->id)
            ->whereNull('revoked_at')
            ->with(['loginSessions' => fn ($query) => $query
                ->where('status', 'active')
                ->with('student:id,full_name,admission_number')
                ->latest('login_time')])
            ->orderBy('name')
            ->get();

        return $this->success($devices->map(fn (Computer $device) => [
            'id' => $device->id,
            'device_uuid' => $device->device_uuid,
            'name' => $device->name,
            'hostname' => $device->hostname,
            'operating_system' => $device->operating_system,
            'agent_version' => $device->agent_version,
            'status' => $device->isOnline() ? 'online' : 'offline',
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'active_session' => $device->loginSessions->first() ? [
                'uuid' => $device->loginSessions->first()->uuid,
                'student' => $device->loginSessions->first()->student,
                'started_at' => $device->loginSessions->first()->login_time->toIso8601String(),
            ] : null,
        ]));
    }
}
