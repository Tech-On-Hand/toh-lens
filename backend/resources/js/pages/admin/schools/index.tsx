import { Form, Head, Link } from '@inertiajs/react';
import SchoolController from '@/actions/App/Http/Controllers/Admin/SchoolController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as classesIndex } from '@/routes/admin/classes';
import { index as computersIndex } from '@/routes/admin/computers';
import { index as studentsIndex } from '@/routes/admin/students';
import type { School } from '@/types';

export default function SchoolsIndex({ schools }: { schools: School[] }) {
    return (
        <>
            <Head title="Schools" />

            <div className="space-y-6 p-4">
                <Heading title="Schools" description="Top-level scoping entity for classes, students, and computers." />

                <Card>
                    <CardHeader>
                        <CardTitle>Add a school</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form {...SchoolController.store.form()} resetOnSuccess className="flex items-end gap-3">
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input id="name" name="name" placeholder="Demo Primary School" required />
                                        <InputError message={errors.name} />
                                    </div>
                                    <Button disabled={processing}>Add school</Button>
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
                                <th className="px-4 py-2 font-medium">Classes</th>
                                <th className="px-4 py-2 font-medium">Students</th>
                                <th className="px-4 py-2 font-medium">Devices</th>
                                <th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {schools.map((school) => (
                                <tr key={school.id} className="border-t">
                                    <td className="px-4 py-2 font-medium">{school.name}</td>
                                    <td className="px-4 py-2">
                                        <Link
                                            href={classesIndex()}
                                            className="text-muted-foreground hover:text-foreground underline underline-offset-2"
                                        >
                                            {school.classes_count}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2">
                                        <Link
                                            href={studentsIndex({ query: { school_id: school.id } })}
                                            className="text-muted-foreground hover:text-foreground underline underline-offset-2"
                                        >
                                            {school.students_count}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2">
                                        <Link
                                            href={computersIndex()}
                                            className="text-muted-foreground hover:text-foreground underline underline-offset-2"
                                        >
                                            {school.computers_count}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <Form {...SchoolController.destroy.form(school.id)}>
                                            {({ processing }) => (
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    disabled={processing}
                                                    onClick={(e) => {
                                                        if (!confirm(`Delete "${school.name}"? This also deletes its classes, students, computers, and sessions.`)) {
                                                            e.preventDefault();
                                                        }
                                                    }}
                                                >
                                                    Delete
                                                </Button>
                                            )}
                                        </Form>
                                    </td>
                                </tr>
                            ))}
                            {schools.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-4 py-6 text-center">
                                        No schools yet.
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
