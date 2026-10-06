import { Form, Head, usePage } from '@inertiajs/react';
import { KeyRound, Monitor, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ComputerController from '@/actions/App/Http/Controllers/Admin/ComputerController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ClassOption, Computer, SchoolOption } from '@/types';

const ROW_GRID = 'grid grid-cols-[1.5fr_1fr_2fr_1fr_1.5fr_auto_auto_auto] items-center gap-3 px-4 py-2';

type PageProps = {
    flash?: { issuedToken?: string };
};

function timeAgo(iso: string | null): string {
    if (!iso) return 'never';
    const hours = (Date.now() - new Date(iso).getTime()) / (1000 * 60 * 60);
    if (hours < 1) return 'less than an hour ago';
    if (hours < 24) return `${Math.floor(hours)}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

export default function ComputersIndex({
    computers,
    schools,
    classes,
}: {
    computers: Computer[];
    schools: SchoolOption[];
    classes: ClassOption[];
}) {
    const { flash } = usePage<PageProps>().props;
    const [dismissedToken, setDismissedToken] = useState(false);
    const issuedToken = dismissedToken ? null : flash?.issuedToken;

    return (
        <>
            <Head title="Devices" />

            <div className="space-y-6 p-4">
                <Heading title="Devices" description="Manage the permanent identity and health of each classroom computer." icon={Monitor} accent="--toh-blue" />

                {issuedToken && (
                    <div className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                        <p className="text-sm font-medium text-amber-900 dark:text-amber-200">
                            New token issued — copy it now, it will not be shown again:
                        </p>
                        <div className="flex items-center gap-2">
                            <code className="bg-background flex-1 overflow-x-auto rounded border px-2 py-1 text-xs">
                                {issuedToken}
                            </code>
                            <Button size="sm" variant="outline" onClick={() => setDismissedToken(true)}>
                                Dismiss
                            </Button>
                        </div>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Add a computer</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form {...ComputerController.store.form()} resetOnSuccess className="flex flex-wrap items-end gap-3">
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
                                        <Label htmlFor="name">Name</Label>
                                        <Input id="name" name="name" placeholder="Lab PC 1" required />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="role">Role</Label>
                                        <NativeSelect id="role" name="role" defaultValue="student">
                                            <option value="student">Student</option>
                                            <option value="teacher">Teacher</option>
                                        </NativeSelect>
                                        <InputError message={errors.role} />
                                    </div>

                                    <Button disabled={processing}><Plus /> Add computer</Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <div className="overflow-x-auto rounded-lg border">
                    <div className={`${ROW_GRID} bg-muted/50 text-muted-foreground text-sm font-medium`}>
                        <div>Name</div>
                        <div>Role</div>
                        <div>School / Class</div>
                        <div>Token</div>
                        <div>Last activity</div>
                        <div />
                        <div />
                        <div />
                    </div>

                    {computers.map((computer) => (
                        <div key={computer.id} className={`${ROW_GRID} border-t text-sm`}>
                            <Form {...ComputerController.update.form(computer.id)} className="contents">
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <input type="hidden" name="school_id" value={computer.school_id} />
                                            <Input name="name" defaultValue={computer.name} className="h-8" />
                                            <InputError message={errors.name} className="text-xs" />
                                        </div>
                                        <NativeSelect name="role" defaultValue={computer.role} className="h-8">
                                            <option value="student">Student</option>
                                            <option value="teacher">Teacher</option>
                                        </NativeSelect>
                                        <NativeSelect name="class_id" defaultValue={computer.class_id ?? ''} className="h-8">
                                            <option value="">{computer.school?.name ?? 'Unknown school'} — no class</option>
                                            {classes
                                                .filter((c) => c.school_id === computer.school_id)
                                                .map((c) => (
                                                    <option key={c.id} value={c.id}>
                                                        {computer.school?.name} — {c.name}
                                                    </option>
                                                ))}
                                        </NativeSelect>
                                        <div className="text-muted-foreground text-xs">
                                            {computer.tokens_count > 0 ? 'Issued' : 'None issued'}
                                        </div>
                                        <div className="text-muted-foreground text-xs" title="Last time this computer's token authenticated a request / a session last synced from it">
                                            <div>Connected: {timeAgo(computer.token_last_used_at)}</div>
                                            <div>Synced: {timeAgo(computer.last_session_synced_at)}</div>
                                        </div>
                                        <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                            Save
                                        </Button>
                                    </>
                                )}
                            </Form>

                            <Form {...ComputerController.issueToken.form(computer.id)} className="contents">
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="secondary"
                                        disabled={processing}
                                        onClick={(e) => {
                                            const message =
                                                computer.tokens_count > 0
                                                    ? `Issue a new token for "${computer.name}"? The current token will stop working immediately.`
                                                    : `Issue a token for "${computer.name}"?`;
                                            if (!confirm(message)) {
                                                e.preventDefault();
                                            } else {
                                                setDismissedToken(false);
                                            }
                                        }}
                                    >
                                        <KeyRound /> {computer.tokens_count > 0 ? 'Reissue token' : 'Issue token'}
                                    </Button>
                                )}
                            </Form>

                            <Form {...ComputerController.destroy.form(computer.id)} className="contents">
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                        title={`Delete "${computer.name}"`}
                                        onClick={(e) => {
                                            if (!confirm(`Delete "${computer.name}"?`)) {
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

                    {computers.length === 0 && (
                        <div className="text-muted-foreground px-4 py-6 text-center text-sm">No computers yet.</div>
                    )}
                </div>
            </div>
        </>
    );
}
