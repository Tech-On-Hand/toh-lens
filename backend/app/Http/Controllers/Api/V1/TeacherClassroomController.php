<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ChatMessage;
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
            'school' => ['id' => $classroom->school->id, 'name' => $classroom->school->name, 'organization_id' => $classroom->school->organization_id],
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
                ->latest('login_time'),
                'browserTabs' => fn ($query) => $query->where('is_active', true)->latest('observed_at'),
                'screenSessions' => fn ($query) => $query->current()->with('viewer:id,name')->latest('id'),
                'sourceBroadcasts' => fn ($query) => $query->current()->latest('id'),
                'helpRequests' => fn ($query) => $query->open()->latest('id')])
            ->orderBy('name')
            ->get();

        // Messages a student has sent that no teacher has opened yet, per open conversation.
        $unread = ChatMessage::query()
            ->whereIn('login_session_id', $devices->map(fn (Computer $device) => $device->loginSessions->first()?->id)->filter()->values())
            ->where('direction', 'to_teacher')->whereNull('read_at')
            ->selectRaw('login_session_id, count(*) as total')->groupBy('login_session_id')
            ->pluck('total', 'login_session_id');

        return $this->success($devices->map(fn (Computer $device) => [
            'id' => $device->id,
            'device_uuid' => $device->device_uuid,
            'name' => $device->name,
            'hostname' => $device->hostname,
            'operating_system' => $device->operating_system,
            'agent_version' => $device->agent_version,
            'status' => $device->isOnline() ? 'online' : 'offline',
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'active_tab' => $device->browserTabs->first() ? [
                'tab_id' => $device->browserTabs->first()->browser_tab_id,
                'url' => $device->browserTabs->first()->url,
                'title' => $device->browserTabs->first()->title,
                'observed_at' => $device->browserTabs->first()->observed_at->toIso8601String(),
            ] : null,
            'active_session' => $device->loginSessions->first() ? [
                'uuid' => $device->loginSessions->first()->uuid,
                'student' => $device->loginSessions->first()->student,
                'started_at' => $device->loginSessions->first()->login_time->toIso8601String(),
            ] : null,
            'watched_by' => $device->screenSessions->first()?->viewer_id === $user->id ? null : $device->screenSessions->first()?->viewer?->name,
            'broadcast_id' => $device->sourceBroadcasts->first()?->uuid,
            'help_request' => $device->helpRequests->first()?->toSummary(),
            'unread_messages' => (int) ($unread[$device->loginSessions->first()?->id] ?? 0),
        ]));
    }

    public function browserTabs(Request $request, Classroom $classroom, Computer $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);
        abort_unless($device->classroom_id === $classroom->id && $device->revoked_at === null, 404);

        return $this->success($device->browserTabs()->orderBy('window_id')->orderBy('browser_tab_id')->get()
            ->map(fn ($tab) => [
                'tab_id' => $tab->browser_tab_id,
                'window_id' => $tab->window_id,
                'browser' => $tab->browser,
                'url' => $tab->url,
                'title' => $tab->title,
                'is_active' => $tab->is_active,
                'observed_at' => $tab->observed_at->toIso8601String(),
            ]));
    }
}
