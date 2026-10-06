import { Head, router } from '@inertiajs/react';
import { Building2, Monitor, Radio, UserPlus, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/native-select';

interface Classroom { id: number; name: string; computers_count: number; }
interface School { id: number; name: string; classrooms: Classroom[]; }
interface Staff { id: number; name: string; email: string; }
interface Props { schools: School[]; staff: Staff[]; }

export default function KlasSetup({ schools, staff }: Props) {
    type IssuedFlash = { issuedEnrollmentCode?: string; issuedInvitationToken?: string };
    const [flash, setFlash] = useState<IssuedFlash>();
    const classrooms = schools.flatMap(school => school.classrooms.map(room => ({ ...room, school })));
    const issuedRef = useRef<HTMLDivElement>(null);

    // Inertia never puts flash data on the reactive page object (it strips it out
    // of every client-side navigation before merging), so it must be read off this
    // one-shot event instead of `usePage().props.flash` — see how the toast on
    // every other admin page already does this via `useFlashToast`.
    useEffect(() => {
        return router.on('flash', (event) => {
            const flashed = (event as CustomEvent).detail?.flash as IssuedFlash;
            if (flashed?.issuedEnrollmentCode || flashed?.issuedInvitationToken) setFlash(flashed);
        });
    }, []);

    // The code/token is shown once; the form that issues it can be scrolled well
    // below this card (`preserveScroll` keeps the submitter's position), so
    // without this it can render off-screen and go unnoticed.
    useEffect(() => {
        if (flash?.issuedEnrollmentCode || flash?.issuedInvitationToken) {
            issuedRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, [flash]);
    const submit = (path: string, form: HTMLFormElement, method: 'post' | 'put' = 'post') => {
        const data = Object.fromEntries(new FormData(form));
        router[method](path, data, { preserveScroll: true });
    };

    return <>
        <Head title="TOH Klas Setup" />
        <div className="flex h-full flex-1 flex-col gap-6 p-4">
            <div><h1 className="flex items-center gap-2.5 text-2xl font-semibold"><span className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"><Radio className="size-5" /></span>TOH Klas foundation</h1><p className="text-muted-foreground">Manage physical classrooms, staff access, and secure device enrollment.</p></div>
            {(flash?.issuedEnrollmentCode || flash?.issuedInvitationToken) && <div ref={issuedRef}><Card className="border-emerald-500 bg-emerald-50 dark:bg-emerald-950"><CardContent className="pt-6"><p className="text-sm font-medium">Copy this value now; it will not be shown again.</p><code className="mt-2 block break-all text-lg">{flash.issuedEnrollmentCode ?? flash.issuedInvitationToken}</code></CardContent></Card></div>}
            <div className="grid gap-5 xl:grid-cols-3">
                <Card><CardHeader><CardTitle className="flex items-center gap-2"><Building2 className="text-primary size-4.5" />Create physical classroom</CardTitle></CardHeader><CardContent><form className="space-y-4" onSubmit={e => { e.preventDefault(); submit('/admin/klas/classrooms', e.currentTarget); }}><Label>School</Label><NativeSelect name="school_id" required><option value="">Choose school</option>{schools.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</NativeSelect><Label>Name</Label><Input name="name" placeholder="Main Computer Lab" required /><Button type="submit"><Building2 /> Create classroom</Button></form></CardContent></Card>
                <Card><CardHeader><CardTitle className="flex items-center gap-2"><Monitor className="text-primary size-4.5" />Enroll a device</CardTitle></CardHeader><CardContent><form className="space-y-4" onSubmit={e => { e.preventDefault(); submit('/admin/klas/enrollment-codes', e.currentTarget); }}><Label>Classroom</Label><NativeSelect name="classroom_id" required><option value="">Choose classroom</option>{classrooms.map(r => <option key={r.id} value={r.id}>{r.school.name} · {r.name}</option>)}</NativeSelect><Button type="submit"><Monitor /> Generate 30-minute code</Button></form></CardContent></Card>
                <Card><CardHeader><CardTitle className="flex items-center gap-2"><UserPlus className="text-primary size-4.5" />Invite staff</CardTitle></CardHeader><CardContent><form className="space-y-4" onSubmit={e => { e.preventDefault(); submit('/admin/klas/invitations', e.currentTarget); }}><Label>School</Label><NativeSelect name="school_id" required><option value="">Choose school</option>{schools.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</NativeSelect><Label>Email</Label><Input name="email" type="email" required /><Label>Role</Label><NativeSelect name="role"><option value="teacher">Teacher</option><option value="school_administrator">School administrator</option></NativeSelect><Button type="submit"><UserPlus /> Create invitation</Button></form></CardContent></Card>
            </div>
            <Card><CardHeader><CardTitle className="flex items-center gap-2"><Building2 className="text-primary size-4.5" />Classrooms</CardTitle></CardHeader><CardContent className="grid gap-4 lg:grid-cols-2">{classrooms.map(room => <div key={room.id} className="rounded-lg border p-4"><div className="mb-3"><strong>{room.name}</strong><p className="text-sm text-muted-foreground">{room.school.name} · {room.computers_count} devices</p></div><form className="flex gap-2" onSubmit={e => { e.preventDefault(); submit(`/admin/klas/classrooms/${room.id}/staff`, e.currentTarget, 'put'); }}><NativeSelect name="user_id" required><option value="">Assign staff</option>{staff.map(user => <option key={user.id} value={user.id}>{user.name}</option>)}</NativeSelect><NativeSelect name="role"><option value="primary_teacher">Primary</option><option value="assistant_teacher">Assistant</option><option value="observer">Observer</option></NativeSelect><Button type="submit" variant="outline"><Users /> Assign</Button></form></div>)}</CardContent></Card>
        </div>
    </>;
}

KlasSetup.layout = {
    breadcrumbs: [
        {
            title: 'Klas Setup',
            href: '/admin/klas',
        },
    ],
};
