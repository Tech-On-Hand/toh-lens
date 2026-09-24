<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ClassroomCommunicationChanged;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\Computer;
use App\Models\HelpRequest;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceCommunicationController extends ApiController
{
    private const MAX_PENDING_ANNOUNCEMENTS = 5;

    /**
     * Announcements this device has not read yet. Fetching one is what marks it
     * delivered, so a device that was offline when it was sent still gets it (until it
     * expires) and the teacher can tell "not delivered" from "delivered, not read".
     */
    public function announcements(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        if ($device->classroom_id === null) {
            return $this->success([]);
        }

        $pending = Announcement::query()
            ->unexpired()
            ->with('sender:id,name')
            ->where('classroom_id', $device->classroom_id)
            ->whereDoesntHave('receipts', fn ($query) => $query->where('computer_id', $device->id)->whereNotNull('read_at'))
            ->orderBy('id')
            ->limit(self::MAX_PENDING_ANNOUNCEMENTS)
            ->get();

        foreach ($pending as $announcement) {
            AnnouncementReceipt::firstOrCreate(
                ['announcement_id' => $announcement->id, 'computer_id' => $device->id],
                ['delivered_at' => now()],
            );
        }

        return $this->success($pending->map(fn (Announcement $announcement) => $announcement->toSummary()));
    }

    public function markAnnouncementRead(Request $request, string $uuid): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $announcement = Announcement::query()->where('uuid', $uuid)->where('classroom_id', $device->classroom_id)->firstOrFail();

        $receipt = AnnouncementReceipt::firstOrCreate(
            ['announcement_id' => $announcement->id, 'computer_id' => $device->id],
            ['delivered_at' => now()],
        );

        if ($receipt->read_at === null) {
            $receipt->update(['read_at' => now()]);
            ClassroomCommunicationChanged::dispatch($announcement->classroom_id, 'announcement');
        }

        return $this->success(['read' => true]);
    }

    /** The device's own open help request, or `null` once it is resolved or cancelled. */
    public function currentHelpRequest(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $help = HelpRequest::query()->open()->where('computer_id', $device->id)->latest('id')->first();

        return $this->success(['help_request' => $help?->toSummary()]);
    }

    /**
     * Raise a hand. The uuid is chosen by the device, so a retry after a dropped
     * connection returns the same request instead of raising a second one, and a
     * device that already has one open keeps just that one.
     */
    public function requestHelp(Request $request): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        if ($device->classroom_id === null) {
            return $this->error('NO_CLASSROOM', 'This device is not assigned to a classroom.', 409);
        }

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'message' => ['nullable', 'string', 'max:200'],
            'session_uuid' => ['nullable', 'uuid'],
        ]);

        // A hand raised while offline is delivered later; it is only still meaningful
        // if the student who raised it is still signed in.
        $session = $device->activeSession();
        if (isset($data['session_uuid'])) {
            $known = $device->loginSessions()->where('uuid', $data['session_uuid'])->first();
            if (! $known) {
                return $this->error('SESSION_UNKNOWN', 'That login session has not reached the server yet.', 409);
            }
            if ($known->status !== 'active') {
                return $this->error('SESSION_ENDED', 'That student has already signed out.', 409);
            }
            $session = $known;
        }

        $help = DB::transaction(function () use ($device, $data, $session) {
            Computer::query()->whereKey($device->id)->lockForUpdate()->first();

            $existing = HelpRequest::query()->where('uuid', $data['uuid'])->first();
            if ($existing) {
                return $existing->computer_id === $device->id ? $existing : null;
            }

            $open = HelpRequest::query()->open()->where('computer_id', $device->id)->latest('id')->first();
            if ($open) {
                return $open;
            }

            return HelpRequest::create([
                'uuid' => $data['uuid'],
                'school_id' => $device->school_id,
                'classroom_id' => $device->classroom_id,
                'computer_id' => $device->id,
                'student_id' => $session?->student_id,
                'message' => trim($data['message'] ?? '') ?: null,
                'status' => 'open',
                'requested_at' => now(),
            ]);
        });

        if ($help === null) {
            return $this->error('REQUEST_ID_TAKEN', 'That request id belongs to another device.', 409);
        }

        if ($help->wasRecentlyCreated) {
            Audit::record('help.requested', null, null, $device, ['help_request_id' => $help->uuid, 'student_id' => $help->student_id]);
            ClassroomCommunicationChanged::dispatch($device->classroom_id, 'help');
        }

        return $this->success($help->toSummary(), $help->wasRecentlyCreated ? 201 : 200);
    }

    public function cancelHelpRequest(Request $request, string $uuid): JsonResponse
    {
        /** @var Computer $device */
        $device = $request->user();
        $help = HelpRequest::query()->where('uuid', $uuid)->where('computer_id', $device->id)->firstOrFail();

        if ($help->status === 'open') {
            $help->update(['status' => 'cancelled', 'resolved_at' => now()]);
            ClassroomCommunicationChanged::dispatch($device->classroom_id, 'help');
        }

        return $this->success($help->toSummary());
    }
}
