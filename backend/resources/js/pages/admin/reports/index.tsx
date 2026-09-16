import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as reportsIndex } from '@/routes/admin/reports';
import type { ClassReport, ClassWithSchool } from '@/types';

type Filters = {
    class_id: number | null;
    from: string;
    to: string;
};

export default function ReportsIndex({
    classes,
    filters,
    report,
}: {
    classes: ClassWithSchool[];
    filters: Filters;
    report: ClassReport | null;
}) {
    const [classId, setClassId] = useState(filters.class_id ?? '');
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(reportsIndex({ query: { class_id: classId || undefined, from, to } }));
    };

    return (
        <>
            <Head title="M&E Reports" />

            <div className="space-y-6 p-4">
                <Heading
                    title="M&E Reports"
                    description="Per-class usage summary computed from kiosk login sessions. Application-level breakdown will appear once LanSchool Air activity data is integrated."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Choose a class and period</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="class_id">Class</Label>
                                <NativeSelect
                                    id="class_id"
                                    value={classId}
                                    onChange={(e) => setClassId(e.target.value)}
                                    required
                                >
                                    <option value="" disabled>
                                        Select a class
                                    </option>
                                    {classes.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.school?.name} — {c.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="from">From</Label>
                                <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="to">To</Label>
                                <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                            </div>

                            <Button type="submit">View report</Button>
                        </form>
                    </CardContent>
                </Card>

                {!report && (
                    <p className="text-muted-foreground text-sm">Select a class above to view its usage report.</p>
                )}

                {report && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {report.class.school_name} — {report.class.name}
                            </CardTitle>
                            <p className="text-muted-foreground text-sm">
                                {filters.from} to {filters.to}
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <Stat label="Total students" value={report.total_students} />
                                <Stat label="Active students" value={report.active_students} />
                                <Stat
                                    label="Avg. session duration"
                                    value={report.average_duration_minutes !== null ? `${report.average_duration_minutes} min` : '—'}
                                />
                                <Stat label="Total sessions" value={report.total_sessions} />
                            </div>

                            <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                Most-used application: not available yet — requires LanSchool Air activity data
                                (see the roadmap's Phase 2/7 items).
                            </p>

                            <div>
                                <h3 className="mb-2 text-sm font-medium">
                                    Students with no recorded usage ({report.no_usage_students.length})
                                </h3>
                                {report.no_usage_students.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Every active student in this class had at least one session.</p>
                                ) : (
                                    <ul className="divide-y rounded-md border text-sm">
                                        {report.no_usage_students.map((s) => (
                                            <li key={s.admission_number} className="flex justify-between px-3 py-2">
                                                <span>{s.full_name}</span>
                                                <span className="text-muted-foreground">{s.admission_number}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-lg border p-3">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="text-2xl font-semibold">{value}</div>
        </div>
    );
}
