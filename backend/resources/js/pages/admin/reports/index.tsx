import { Head, router } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as reportsIndex } from '@/routes/admin/reports';
import type { ClassReport, ClassWithSchool, GradeOption } from '@/types';

type Filters = {
    class_id: number | null;
    school_id: number | null;
    grade: string | null;
    from: string;
    to: string;
};

// One dropdown picks either a single class ("class:12") or a whole grade ("grade:3:Grade 4").
const classValue = (id: number) => `class:${id}`;
const gradeValue = (g: { school_id: number; grade: string }) => `grade:${g.school_id}:${g.grade}`;

function targetQuery(target: string): { class_id?: string; school_id?: string; grade?: string } {
    if (target.startsWith('class:')) return { class_id: target.slice('class:'.length) };
    if (target.startsWith('grade:')) {
        const [, schoolId, ...grade] = target.split(':');
        return { school_id: schoolId, grade: grade.join(':') };
    }
    return {};
}

export default function ReportsIndex({
    classes,
    grades,
    filters,
    report,
}: {
    classes: ClassWithSchool[];
    grades: GradeOption[];
    filters: Filters;
    report: ClassReport | null;
}) {
    const initialTarget = filters.class_id
        ? classValue(filters.class_id)
        : filters.grade && filters.school_id
          ? gradeValue({ school_id: filters.school_id, grade: filters.grade })
          : '';
    const [target, setTarget] = useState(initialTarget);
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(reportsIndex({ query: { ...targetQuery(target), from, to } }));
    };

    return (
        <>
            <Head title="M&E Reports" />

            <div className="space-y-6 p-4">
                <Heading
                    title="M&E Reports"
                    description="Usage summary for a class, or a whole grade, computed from kiosk login sessions. Application-level breakdown will appear once LanSchool Air activity data is integrated."
                    icon={BarChart3}
                    accent="--toh-purple"
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Choose a class or grade, and a period</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="target">Class or grade</Label>
                                <NativeSelect id="target" value={target} onChange={(e) => setTarget(e.target.value)} required>
                                    <option value="" disabled>
                                        Select a class or grade
                                    </option>
                                    {grades.length > 0 && (
                                        <optgroup label="Whole grade (all streams together)">
                                            {grades.map((g) => (
                                                <option key={gradeValue(g)} value={gradeValue(g)}>
                                                    {g.school_name} — {g.grade} ({g.classes_count} {g.classes_count === 1 ? 'class' : 'classes'})
                                                </option>
                                            ))}
                                        </optgroup>
                                    )}
                                    <optgroup label="Single class">
                                        {classes.map((c) => (
                                            <option key={c.id} value={classValue(c.id)}>
                                                {c.school?.name} — {c.name}
                                            </option>
                                        ))}
                                    </optgroup>
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

                            <Button type="submit"><BarChart3 /> View report</Button>
                        </form>
                    </CardContent>
                </Card>

                {!report && (
                    <p className="text-muted-foreground text-sm">Select a class or a whole grade above to view its usage report.</p>
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

                            {report.scope === 'grade' && (
                                <div>
                                    <h3 className="mb-2 text-sm font-medium">By class</h3>
                                    <div className="overflow-x-auto rounded-md border">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-muted/50 text-muted-foreground">
                                                <tr>
                                                    <th className="px-3 py-2 font-medium">Class</th>
                                                    <th className="px-3 py-2 font-medium">Students</th>
                                                    <th className="px-3 py-2 font-medium">Active</th>
                                                    <th className="px-3 py-2 font-medium">Sessions</th>
                                                    <th className="px-3 py-2 font-medium">Avg. duration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {report.breakdown.map((row) => (
                                                    <tr key={row.id} className="border-t">
                                                        <td className="px-3 py-2 font-medium">{row.stream ?? row.name}</td>
                                                        <td className="px-3 py-2">{row.total_students}</td>
                                                        <td className="px-3 py-2">
                                                            {row.active_students}
                                                            {row.total_students > 0 && (
                                                                <span className="text-muted-foreground"> ({Math.round((row.active_students / row.total_students) * 100)}%)</span>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2">{row.total_sessions}</td>
                                                        <td className="px-3 py-2">{row.average_duration_minutes !== null ? `${row.average_duration_minutes} min` : '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                Most-used application: not available yet — requires LanSchool Air activity data
                                (see the roadmap's Phase 2/7 items).
                            </p>

                            <div>
                                <h3 className="mb-2 text-sm font-medium">
                                    Students with no recorded usage ({report.no_usage_students.length})
                                </h3>
                                {report.no_usage_students.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">
                                        Every active student in this {report.scope === 'grade' ? 'grade' : 'class'} had at least one session.
                                    </p>
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
