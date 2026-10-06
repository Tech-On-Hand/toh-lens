import { Head, Link } from '@inertiajs/react';
import { Activity, BarChart3, GraduationCap, LayoutGrid, Monitor, Radio, School, Users } from 'lucide-react';
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

export default function Dashboard() {
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
