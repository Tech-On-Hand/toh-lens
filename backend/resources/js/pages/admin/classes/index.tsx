import { Form, Head } from '@inertiajs/react';
import { GraduationCap, Pencil, Plus, Sprout, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SchoolClassController from '@/actions/App/Http/Controllers/Admin/SchoolClassController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { SeedCbcPanel } from '@/components/seed-cbc-panel';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CbcLevel, SchoolClassRow, SchoolOption } from '@/types';

type Teacher = { id: number; name: string };

export default function ClassesIndex({
    classes,
    schools,
    teachers,
    cbcLevels,
}: {
    classes: SchoolClassRow[];
    schools: SchoolOption[];
    teachers: Teacher[];
    cbcLevels: CbcLevel[];
}) {
    // Which row is open for editing its grade and stream (one at a time).
    const [editingId, setEditingId] = useState<number | null>(null);
    const [seeding, setSeeding] = useState(false);

    return (
        <>
            <Head title="Classes" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading title="Classes" description="Groups students and computers under a school and (optionally) a teacher." icon={GraduationCap} accent="--toh-purple" />
                    {!seeding && schools.length > 0 && (
                        <Button type="button" variant="outline" onClick={() => setSeeding(true)}>
                            <Sprout /> Seed Kenya (CBC) classes
                        </Button>
                    )}
                </div>

                {seeding && <SeedCbcPanel schools={schools} classes={classes} levels={cbcLevels} onDone={() => setSeeding(false)} />}

                <Card>
                    <CardHeader>
                        <CardTitle>Add a class</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-muted-foreground text-sm">
                            Give the <strong>grade</strong> and the <strong>stream</strong> (such as a colour) separately, for example Grade 4 and Blue. The class is
                            named "Grade 4 Blue", and reports can then add up every stream of Grade 4 together.
                        </p>
                        <Form {...SchoolClassController.store.form()} resetOnSuccess className="flex flex-wrap items-start gap-3">
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="school_id">School</Label>
                                        <NativeSelect id="school_id" name="school_id" defaultValue="" required>
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
                                        <Label htmlFor="grade">Grade</Label>
                                        <Input id="grade" name="grade" placeholder="Grade 4" />
                                        <InputError message={errors.grade} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="stream">Stream (optional)</Label>
                                        <Input id="stream" name="stream" placeholder="Blue" />
                                        <InputError message={errors.stream} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name (optional)</Label>
                                        <Input id="name" name="name" placeholder="Built from grade + stream" />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="teacher_id">Teacher (optional)</Label>
                                        <NativeSelect id="teacher_id" name="teacher_id" defaultValue="">
                                            <option value="">No teacher assigned</option>
                                            {teachers.map((teacher) => (
                                                <option key={teacher.id} value={teacher.id}>
                                                    {teacher.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <InputError message={errors.teacher_id} />
                                    </div>

                                    <Button className="mt-6" disabled={processing}><Plus /> Add class</Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">Grade</th>
                                <th className="px-4 py-2 font-medium">Stream</th>
                                <th className="px-4 py-2 font-medium">School</th>
                                <th className="px-4 py-2 font-medium">Teacher</th>
                                <th className="px-4 py-2 font-medium">Students</th>
                                <th className="px-4 py-2 font-medium">Devices</th>
                                <th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {classes.map((schoolClass) => (
                                <tr key={schoolClass.id} className="border-t">
                                    <td className="px-4 py-2 font-medium">{schoolClass.name}</td>
                                    {editingId === schoolClass.id ? (
                                        <td colSpan={2} className="px-4 py-2">
                                            <Form
                                                {...SchoolClassController.update.form(schoolClass.id)}
                                                onSuccess={() => setEditingId(null)}
                                                className="flex flex-wrap items-start gap-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <div className="grid gap-1">
                                                            <Input name="grade" defaultValue={schoolClass.grade ?? ''} placeholder="Grade 4" aria-label="Grade" className="h-8 w-32" autoFocus />
                                                            <InputError message={errors.grade} />
                                                        </div>
                                                        <div className="grid gap-1">
                                                            <Input name="stream" defaultValue={schoolClass.stream ?? ''} placeholder="Blue" aria-label="Stream" className="h-8 w-28" />
                                                            <InputError message={errors.stream} />
                                                        </div>
                                                        <Button size="sm" disabled={processing}>Save</Button>
                                                        <Button type="button" size="sm" variant="ghost" onClick={() => setEditingId(null)}>Cancel</Button>
                                                    </>
                                                )}
                                            </Form>
                                        </td>
                                    ) : (
                                        <>
                                            <td className="px-4 py-2">{schoolClass.grade ?? <span className="text-muted-foreground">Not set</span>}</td>
                                            <td className="px-4 py-2">{schoolClass.stream ?? '—'}</td>
                                        </>
                                    )}
                                    <td className="px-4 py-2">{schoolClass.school?.name ?? '—'}</td>
                                    <td className="px-4 py-2">{schoolClass.teacher?.name ?? '—'}</td>
                                    <td className="px-4 py-2">{schoolClass.students_count}</td>
                                    <td className="px-4 py-2">{schoolClass.computers_count}</td>
                                    <td className="px-4 py-2">
                                        <div className="flex justify-end gap-2">
                                            <Button type="button" variant="outline" size="sm" onClick={() => setEditingId(schoolClass.id)}>
                                                <Pencil /> Grade / stream
                                            </Button>
                                            <Form {...SchoolClassController.destroy.form(schoolClass.id)}>
                                                {({ processing }) => (
                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                        disabled={processing}
                                                        onClick={(e) => {
                                                            if (!confirm(`Delete "${schoolClass.name}"?`)) {
                                                                e.preventDefault();
                                                            }
                                                        }}
                                                    >
                                                        <Trash2 /> Delete
                                                    </Button>
                                                )}
                                            </Form>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {classes.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground px-4 py-6 text-center">
                                        No classes yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
