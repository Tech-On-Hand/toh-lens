<?php

namespace App\Console\Commands;

use App\Events\DevicePolicyChanged;
use App\Events\FocusSessionChanged;
use App\Models\Classroom;
use App\Models\FocusSession;
use App\Support\Audit;
use App\Support\PolicyResolver;
use Illuminate\Console\Command;

class ExpireFocusSessions extends Command
{
    protected $signature = 'focus-sessions:expire';

    protected $description = 'Close focus sessions that reached their deadline and tell the classroom';

    public function handle(): int
    {
        // A session past its deadline is already inactive everywhere; this records
        // the end, writes the audit entry, and notifies devices and teachers.
        FocusSession::query()
            ->whereNull('ended_at')
            ->where('expires_at', '<=', now())
            ->eachById(function (FocusSession $session) {
                $session->update(['ended_at' => $session->expires_at, 'end_reason' => 'expired']);
                Audit::record('focus.expired', null, Classroom::find($session->classroom_id), null, ['focus_id' => $session->uuid], $session->school_id);
                FocusSessionChanged::dispatch($session, 'expired');
                DevicePolicyChanged::dispatch(PolicyResolver::deviceUuids($session->school_id, $session->classroom_id));
            });

        return self::SUCCESS;
    }
}
