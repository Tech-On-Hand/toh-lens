import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, BarChart3, Download, GraduationCap, LayoutGrid, Monitor, MonitorSmartphone, Radio, School, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import Heading from '@/components/heading';
import { index as classesIndex } from '@/routes/admin/classes';
import { index as computersIndex } from '@/routes/admin/computers';
import { index as liveIndex } from '@/routes/admin/live';
import { index as reportsIndex } from '@/routes/admin/reports';
import { index as schoolsIndex } from '@/routes/admin/schools';
import { index as studentsIndex } from '@/routes/admin/students';
import { dashboard } from '@/routes';

// Each section keeps the same accent wherever it shows up (this card, that
// page's own Heading icon) — --toh-green is reserved for "live" status, so
// it only appears on Live Sessions here.
const links: { title: string; description: string; href: string; icon: LucideIcon; accent: string }[] = [
    { title: 'Klas Setup', description: 'Classrooms, staff access, device enrollment', href: '/admin/klas', icon: Radio, accent: '--toh-navy' },
    { title: 'Schools', description: 'Top-level scoping for classes and students', href: schoolsIndex().url, icon: School, accent: '--toh-blue' },
    { title: 'Classes', description: 'Groups under a school and teacher', href: classesIndex().url, icon: GraduationCap, accent: '--toh-purple' },
    { title: 'Students', description: 'Roster for kiosk admission numbers', href: studentsIndex().url, icon: Users, accent: '--toh-orange' },
    { title: 'Devices', description: 'Identity and health of classroom computers', href: computersIndex().url, icon: Monitor, accent: '--toh-blue' },
    { title: 'Live Sessions', description: 'Everyone logged in on a kiosk right now', href: liveIndex().url, icon: Activity, accent: '--toh-green' },
    { title: 'M&E Reports', description: 'Per-class usage summaries', href: reportsIndex().url, icon: BarChart3, accent: '--toh-purple' },
];

export default function Dashboard({ teacherInstaller }: { teacherInstaller?: { name: string; size_mb: number } | null }) {
    const { auth } = usePage().props;

    // Teachers can sign in here but every admin page is closed to them; point them to their app.
    if (!auth.is_administrator) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="flex h-full flex-1 flex-col gap-6 p-4">
                    <Heading title={`Welcome, ${auth.user.name}`} description="This website is for school administrators." icon={LayoutGrid} />
                    <div className="border-sidebar-border/70 flex max-w-2xl items-start gap-3 rounded-xl border p-4">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-(--toh-blue)/10 text-(--toh-blue)">
                            <MonitorSmartphone className="size-5" />
                        </span>
                        <div className="space-y-3 text-sm">
                            <p>Your account is set up, but there is nothing to manage on this website. You do your work in the apps:</p>
                            <ul className="list-disc space-y-1 pl-5">
                                <li>
                                    <strong>Teacher app</strong>: see your classrooms, watch student screens, send announcements and chat, and focus
                                    the class or block sites. Sign in with this same email and password.
                                </li>
                                <li>
                                    <strong>Student app</strong>: runs on the classroom computers, where students sign in with their admission
                                    number. It is installed on those computers by your school.
                                </li>
                            </ul>
                            {teacherInstaller ? (
                                <div className="space-y-1">
                                    {/* A plain link, not an Inertia visit: this is a file download. */}
                                    <a
                                        href="/downloads/teacher"
                                        className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium"
                                    >
                                        <Download className="size-4" />
                                        Download the Teacher app for Windows
                                    </a>
                                    <p className="text-muted-foreground text-xs">
                                        {teacherInstaller.name} ({teacherInstaller.size_mb} MB). Windows may warn that the app is from an unknown
                                        publisher; choose "More info" then "Run anyway".
                                    </p>
                                </div>
                            ) : (
                                <p className="text-muted-foreground">If you do not have the Teacher app yet, ask your school administrator for the installer.</p>
                            )}
                        </div>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading title="Dashboard" description="Jump into any part of TOH Klas." icon={LayoutGrid} />
                <div className="grid auto-rows-min gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {links.map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            className="group border-sidebar-border/70 flex items-start gap-3 rounded-xl border p-4 transition-colors hover:border-(--link-accent)/40 hover:bg-(--link-accent)/3"
                            style={{ '--link-accent': `var(${link.accent})` } as React.CSSProperties}
                        >
                            <span
                                className="flex size-10 shrink-0 items-center justify-center rounded-lg"
                                style={{ background: `color-mix(in srgb, var(${link.accent}) 12%, white)`, color: `var(${link.accent})` }}
                            >
                                <link.icon className="size-5" />
                            </span>
                            <span>
                                <span className="block font-medium group-hover:text-(--link-accent)">{link.title}</span>
                                <span className="text-muted-foreground text-sm">{link.description}</span>
                            </span>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
