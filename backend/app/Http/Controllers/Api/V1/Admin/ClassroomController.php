<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClassroomController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSchoolAdministrator((int) $data['school_id']), 403);

        $classroom = Classroom::create([
            'school_id' => $data['school_id'],
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
        ]);

        return $this->success($classroom, 201);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organizationIds = $user->organizations()->wherePivot('role', 'administrator')->pluck('organizations.id');
        $schoolIds = $user->schools()->wherePivot('role', 'administrator')->pluck('schools.id');

        $classrooms = Classroom::query()
            ->whereHas('school', fn ($query) => $query
                ->whereIn('organization_id', $organizationIds)
                ->orWhereIn('id', $schoolIds))
            ->with('school:id,name')
            ->withCount('computers')
            ->orderBy('name')
            ->get();

        return $this->success($classrooms);
    }

    public function assignStaff(Request $request, Classroom $classroom, User $staff): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->isSchoolAdministrator($classroom->school_id), 403);
        $data = $request->validate(['role' => ['required', 'in:primary_teacher,assistant_teacher,observer']]);
        abort_unless($staff->schools()->whereKey($classroom->school_id)->exists(), 422);

        $classroom->users()->syncWithoutDetaching([$staff->id => ['role' => $data['role']]]);

        return $this->success(['assigned' => true]);
    }
}
