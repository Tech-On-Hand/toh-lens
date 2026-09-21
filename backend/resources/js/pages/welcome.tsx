import { Head, Link } from '@inertiajs/react';
import { dashboard, login } from '@/routes';

interface Props { auth: { user: unknown | null } }

export default function Welcome({ auth }: Props) {
    return <>
        <Head title="TOH Klas" />
        <main className="min-h-screen bg-[#f4f7f3] text-[#17241e]">
            <nav className="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
                <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-[#23613f] font-bold text-white">K</span><strong className="text-lg">TOH Klas</strong></div>
                <Link className="rounded-lg border border-[#cad7cc] bg-white px-4 py-2 text-sm font-medium" href={auth.user ? dashboard() : login()}>{auth.user ? 'Open administration' : 'Staff sign in'}</Link>
            </nav>
            <section className="mx-auto grid min-h-[75vh] max-w-6xl place-items-center px-6 text-center">
                <div className="max-w-3xl"><p className="mb-5 text-xs font-bold tracking-[.2em] text-[#587462]">TECH ON HAND</p><h1 className="text-5xl font-semibold tracking-tight md:text-7xl">A clearer digital classroom.</h1><p className="mx-auto mt-7 max-w-2xl text-lg leading-8 text-[#617168]">TOH Klas connects teachers, shared student computers, and learning activity—built for real school labs and unreliable connectivity.</p></div>
            </section>
        </main>
    </>;
}
