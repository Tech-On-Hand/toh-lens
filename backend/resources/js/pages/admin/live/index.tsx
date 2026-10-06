import { Head, router } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { useEffect } from 'react';
import Heading from '@/components/heading';
import { NativeSelect } from '@/components/native-select';
import { Label } from '@/components/ui/label';
import { index as liveIndex } from '@/routes/admin/live';
import type { LiveSession, SchoolOption } from '@/types';

const POLL_INTERVAL_MS = 20_000;
const ROW_GRID = 'grid grid-cols-[2fr_1.5fr_1.5fr_1.5fr_1fr] items-center gap-3 px-4 py-2';

function formatDuration(minutes: number): string {
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m`;
    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

export default function LiveSessionsIndex({
    sessions,
    schools,
    filters,
}: {
    sessions: LiveSession[];
    schools: SchoolOption[];
    filters: { school_id: number | null };
}) {
    // "Live" here means polled, not push-based — good enough for a status
    // view a staff member glances at, without adding a websocket dependency.
    useEffect(() => {
        // reload() always preserves scroll/state for a partial reload like
        // this — those options don't exist on it because they're implied.
        const interval = setInterval(() => {
            router.reload({ only: ['sessions'] });
        }, POLL_INTERVAL_MS);
        return () => clearInterval(interval);
    }, []);

    return (
        <>
            <Head title="Live Sessions" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Live Sessions"
                    description="Everyone currently logged in on a kiosk right now, across all computers. Refreshes automatically."
                    icon={Activity}
                    accent="--toh-green"
                />

                <div className="flex items-center gap-2">
                    <Label htmlFor="school_filter" className="text-sm">
                        Filter by school
                    </Label>
                    <NativeSelect
                        id="school_filter"
                        className="w-auto"
                        defaultValue={filters.school_id ?? ''}
                        onChange={(e) => {
                            const value = e.target.value;
                            router.get(liveIndex({ query: value ? { school_id: value } : {} }));
                        }}
                    >
                        <option value="">All schools</option>
                        {schools.map((school) => (
                            <option key={school.id} value={school.id}>
                                {school.name}
                            </option>
                        ))}
                    </NativeSelect>

                    <span className="ml-auto flex items-center gap-1.5 text-sm">
                        {sessions.length > 0 && <span className="size-2 rounded-full bg-(--toh-green)" />}
                        <span className="text-muted-foreground">{sessions.length} logged in</span>
                    </span>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <div className={`${ROW_GRID} bg-muted/50 text-muted-foreground text-sm font-medium`}>
                        <div>Student</div>
                        <div>School</div>
                        <div>Class</div>
                        <div>Device</div>
                        <div>Logged in for</div>
                    </div>

                    {sessions.map((session) => (
                        <div key={session.id} className={`${ROW_GRID} border-t text-sm`}>
                            <div>
                                <div className="font-medium">{session.full_name ?? 'Unknown student'}</div>
                                <div className="text-muted-foreground text-xs">{session.admission_number}</div>
                            </div>
                            <div>{session.school_name ?? '—'}</div>
                            <div>{session.class_name ?? '—'}</div>
                            <div>{session.computer_name ?? '—'}</div>
                            <div>{formatDuration(session.minutes_logged_in)}</div>
                        </div>
                    ))}

                    {sessions.length === 0 && (
                        <div className="text-muted-foreground px-4 py-6 text-center text-sm">
                            No one is currently logged in on a kiosk.
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
