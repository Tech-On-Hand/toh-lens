import { Form, Head, router, usePage } from '@inertiajs/react';
import { Plus, Trash2, Upload, Users } from 'lucide-react';
import StudentController from '@/actions/App/Http/Controllers/Admin/StudentController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as studentsIndex } from '@/routes/admin/students';
import type { ClassOption, SchoolOption, Student } from '@/types';

const ROW_GRID = 'grid grid-cols-[1.5fr_1.5fr_2fr_1fr_auto_auto] items-center gap-3 px-4 py-2';

type PageProps = {
    flash?: { importIssues?: string[] };
};

export default function StudentsIndex({
    students,
    schools,
    classes,
    filters,
}: {
    students: Student[];
    schools: SchoolOption[];
    classes: ClassOption[];
    filters: { school_id: number | null };
}) {
    const { flash } = usePage<PageProps>().props;
    const importIssues = flash?.importIssues ?? [];

    const classLabel = (schoolId: number, classId: number | null) => {
        if (!classId) return null;
        return classes.find((c) => c.id === classId && c.school_id === schoolId) ?? null;
    };

    return (
        <>
            <Head title="Students" />

            <div className="space-y-6 p-4">
                <Heading title="Students" description="Roster used to validate admission numbers on the kiosk gate." icon={Users} />

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
                            router.get(studentsIndex({ query: value ? { school_id: value } : {} }));
                        }}
                    >
                        <option value="">All schools</option>
                        {schools.map((school) => (
                            <option key={school.id} value={school.id}>
                                {school.name}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Add a student</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form {...StudentController.store.form()} resetOnSuccess className="flex flex-wrap items-end gap-3">
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="school_id">School</Label>
                                        <NativeSelect
                                            id="school_id"
                                            name="school_id"
                                            defaultValue={filters.school_id ?? ''}
                                            required
                                        >
                                            <option value="" disabled>
                                                Select a school
                                            </option>
                                            {schools.map((school) => (
                                                <option key={school.id} value={school.id}>
                                                    {school.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <InputError message={errors.school_id} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="class_id">Class (optional)</Label>
                                        <NativeSelect id="class_id" name="class_id" defaultValue="">
                                            <option value="">No class</option>
                                            {classes.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <InputError message={errors.class_id} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="admission_number">Admission number</Label>
                                        <Input id="admission_number" name="admission_number" placeholder="1001" required />
                                        <InputError message={errors.admission_number} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="full_name">Full name</Label>
                                        <Input id="full_name" name="full_name" placeholder="Amara Otieno" required />
                                        <InputError message={errors.full_name} />
                                    </div>

                                    <Button disabled={processing}><Plus /> Add student</Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Bulk import from CSV</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-muted-foreground text-sm">
                            Columns: <code>admission_number</code>, <code>full_name</code>, optionally{' '}
                            <code>class_name</code> (matched by name within the chosen school) and{' '}
                            <code>is_active</code> (true/false, defaults to true). Re-uploading with the same
                            admission numbers updates those students instead of duplicating them.
                        </p>

                        <Form {...StudentController.import.form()} resetOnSuccess className="flex flex-wrap items-end gap-3">
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="import_school_id">School</Label>
                                        <NativeSelect
                                            id="import_school_id"
                                            name="school_id"
                                            defaultValue={filters.school_id ?? ''}
                                            required
                                        >
                                            <option value="" disabled>
                                                Select a school
                                            </option>
                                            {schools.map((school) => (
                                                <option key={school.id} value={school.id}>
                                                    {school.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <InputError message={errors.school_id} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="csv">CSV file</Label>
                                        <input id="csv" name="csv" type="file" accept=".csv,text/csv" required />
                                        <InputError message={errors.csv} />
                                    </div>

                                    <Button disabled={processing}><Upload /> {processing ? 'Importing…' : 'Import'}</Button>
                                </>
                            )}
                        </Form>

                        {importIssues.length > 0 && (
                            <div className="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
                                <p className="font-medium text-amber-900 dark:text-amber-200">
                                    {importIssues.length} issue(s) from the last import:
                                </p>
                                <ul className="list-disc space-y-0.5 pl-5 text-amber-800 dark:text-amber-200">
                                    {importIssues.map((issue, index) => (
                                        <li key={index}>{issue}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="overflow-x-auto rounded-lg border">
                    <div className={`${ROW_GRID} bg-muted/50 text-muted-foreground text-sm font-medium`}>
                        <div>Admission #</div>
                        <div>Full name</div>
                        <div>School / Class</div>
                        <div>Active</div>
                        <div />
                        <div />
                    </div>

                    {students.map((student) => (
                        <div key={student.id} className={`${ROW_GRID} border-t text-sm`}>
                            <Form {...StudentController.update.form(student.id)} className="contents">
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <input type="hidden" name="school_id" value={student.school_id} />
                                            <Input name="admission_number" defaultValue={student.admission_number} className="h-8" />
                                            <InputError message={errors.admission_number} className="text-xs" />
                                        </div>
                                        <div className="grid gap-1">
                                            <Input name="full_name" defaultValue={student.full_name} className="h-8" />
                                            <InputError message={errors.full_name} className="text-xs" />
                                        </div>
                                        <NativeSelect
                                            name="class_id"
                                            defaultValue={classLabel(student.school_id, student.class_id)?.id ?? ''}
                                            className="h-8"
                                        >
                                            <option value="">
                                                {student.school?.name ?? 'Unknown school'} — no class
                                            </option>
                                            {classes
                                                .filter((c) => c.school_id === student.school_id)
                                                .map((c) => (
                                                    <option key={c.id} value={c.id}>
                                                        {student.school?.name} — {c.name}
                                                    </option>
                                                ))}
                                        </NativeSelect>
                                        <label className="flex items-center justify-center gap-1">
                                            {/* A checkbox sends nothing at all when unchecked, so a hidden
                                                "0" sent first guarantees the field is always present —
                                                the browser sends both, and the server sees the checkbox's
                                                "1" only when it was actually checked. */}
                                            <input type="hidden" name="is_active" value="0" />
                                            <input
                                                type="checkbox"
                                                name="is_active"
                                                value="1"
                                                defaultChecked={student.is_active}
                                            />
                                        </label>
                                        <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                            Save
                                        </Button>
                                    </>
                                )}
                            </Form>

                            <Form {...StudentController.destroy.form(student.id)} className="contents">
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                        title={`Delete ${student.full_name}`}
                                        onClick={(e) => {
                                            if (!confirm(`Delete ${student.full_name}?`)) {
                                                e.preventDefault();
                                            }
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ))}

                    {students.length === 0 && (
                        <div className="text-muted-foreground px-4 py-6 text-center text-sm">No students yet.</div>
                    )}
                </div>
            </div>
        </>
    );
}
