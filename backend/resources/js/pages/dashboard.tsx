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

const links: { title: string; description: string; href: string; icon: LucideIcon }[] = [
    { title: 'Klas Setup', description: 'Classrooms, staff access, device enrollment', href: '/admin/klas', icon: Radio },
    { title: 'Schools', description: 'Top-level scoping for classes and students', href: schoolsIndex().url, icon: School },
    { title: 'Classes', description: 'Groups under a school and teacher', href: classesIndex().url, icon: GraduationCap },
    { title: 'Students', description: 'Roster for kiosk admission numbers', href: studentsIndex().url, icon: Users },
    { title: 'Devices', description: 'Identity and health of classroom computers', href: computersIndex().url, icon: Monitor },
    { title: 'Live Sessions', description: 'Everyone logged in on a kiosk right now', href: liveIndex().url, icon: Activity },
    { title: 'M&E Reports', description: 'Per-class usage summaries', href: reportsIndex().url, icon: BarChart3 },
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
                            className="group border-sidebar-border/70 hover:border-primary/40 hover:bg-primary/3 flex items-start gap-3 rounded-xl border p-4 transition-colors"
                        >
                            <span className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                                <link.icon className="size-5" />
                            </span>
                            <span>
                                <span className="group-hover:text-primary block font-medium">{link.title}</span>
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
