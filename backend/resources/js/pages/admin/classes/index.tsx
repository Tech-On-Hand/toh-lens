import { Form, Head } from '@inertiajs/react';
import { GraduationCap, Plus, Trash2 } from 'lucide-react';
import SchoolClassController from '@/actions/App/Http/Controllers/Admin/SchoolClassController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SchoolClassRow, SchoolOption } from '@/types';

type Teacher = { id: number; name: string };

export default function ClassesIndex({
    classes,
    schools,
    teachers,
}: {
    classes: SchoolClassRow[];
    schools: SchoolOption[];
    teachers: Teacher[];
}) {
    return (
        <>
            <Head title="Classes" />

            <div className="space-y-6 p-4">
                <Heading title="Classes" description="Groups students and computers under a school and (optionally) a teacher." icon={GraduationCap} />

                <Card>
                    <CardHeader>
                        <CardTitle>Add a class</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form {...SchoolClassController.store.form()} resetOnSuccess className="flex flex-wrap items-end gap-3">
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
                                        <Label htmlFor="name">Name</Label>
                                        <Input id="name" name="name" placeholder="Grade 4 Blue" required />
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

                                    <Button disabled={processing}><Plus /> Add class</Button>
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
                                    <td className="px-4 py-2">{schoolClass.school?.name ?? '—'}</td>
                                    <td className="px-4 py-2">{schoolClass.teacher?.name ?? '—'}</td>
                                    <td className="px-4 py-2">{schoolClass.students_count}</td>
                                    <td className="px-4 py-2">{schoolClass.computers_count}</td>
                                    <td className="px-4 py-2 text-right">
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
                                    </td>
                                </tr>
                            ))}
                            {classes.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
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
