<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ClassroomCommunicationChanged;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Models\Computer;
use App\Models\HelpRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeacherCommunicationController extends ApiController
{
    private const RECENT_ANNOUNCEMENTS = 10;

    public function sendAnnouncement(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'duration_minutes' => ['nullable', 'integer', 'between:1,120'],
        ]);

        $announcement = Announcement::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'sent_by' => $user->id,
            'message' => trim($data['message']),
            'sent_at' => now(),
            'expires_at' => now()->addMinutes($data['duration_minutes'] ?? 10),
        ]);

        Audit::record('announcement.sent', $user, $classroom, null, ['announcement_id' => $announcement->uuid]);

        return $this->success($this->withCounts($announcement, $classroom), 201);
    }

    /** The most recent announcements, with how many devices got and read each. */
    public function announcements(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $announcements = Announcement::query()
            ->with('sender:id,name')
            ->where('classroom_id', $classroom->id)
            ->latest('id')
            ->limit(self::RECENT_ANNOUNCEMENTS)
            ->get();

        return $this->success($announcements->map(fn (Announcement $announcement) => $this->withCounts($announcement, $classroom)));
    }

    public function helpRequests(Request $request, Classroom $classroom): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canViewClassroom($classroom), 403);

        $requests = HelpRequest::query()
            ->open()
            ->with(['computer:id,name', 'student:id,full_name'])
            ->where('classroom_id', $classroom->id)
            ->orderBy('requested_at')
            ->get();

        return $this->success($requests->map(fn (HelpRequest $help) => [
            ...$help->toSummary(),
            'device_id' => $help->computer_id,
            'device_name' => $help->computer->name,
            'student_name' => $help->student?->full_name,
        ]));
    }

    public function resolveHelpRequest(Request $request, Classroom $classroom, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canControlClassroom($classroom), 403);

        $help = HelpRequest::query()->where('uuid', $uuid)->where('classroom_id', $classroom->id)->firstOrFail();

        if ($help->status === 'open') {
            $help->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $user->id]);
            Audit::record('help.resolved', $user, $classroom, $help->computer, ['help_request_id' => $help->uuid]);
            ClassroomCommunicationChanged::dispatch($classroom->id, 'help');
        }

        return $this->success($help->toSummary());
    }

    /** @return array<string, mixed> */
    private function withCounts(Announcement $announcement, Classroom $classroom): array
    {
        $announcement->loadMissing('sender:id,name');

        return [
            ...$announcement->toSummary(),
            'total_devices' => Computer::query()->where('classroom_id', $classroom->id)->whereNull('revoked_at')->count(),
            'delivered' => $announcement->receipts()->count(),
            'read' => $announcement->receipts()->whereNotNull('read_at')->count(),
        ];
    }
}
