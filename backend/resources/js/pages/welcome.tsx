import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

interface Props { auth: { user: unknown | null } }

export default function Welcome({ auth }: Props) {
    return <>
        <Head title="TOH Klas" />
        <main className="toh-pattern-bg text-foreground min-h-screen">
            <nav className="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
                <div className="flex items-center gap-3">
                    <span className="bg-primary text-primary-foreground grid size-10 place-items-center rounded-xl">
                        <AppLogoIcon className="size-5 fill-current" />
                    </span>
                    <strong className="text-lg">TOH Klas</strong>
                </div>
                <Button variant="outline" asChild>
                    <Link href={auth.user ? dashboard() : login()}>{auth.user ? 'Open administration' : 'Staff sign in'}</Link>
                </Button>
            </nav>
            <section className="mx-auto grid min-h-[75vh] max-w-6xl place-items-center px-6 text-center">
                <div className="max-w-3xl">
                    <p className="text-primary mb-5 text-xs font-bold tracking-[.2em]">TECH ON HAND</p>
                    <h1 className="text-foreground text-5xl font-semibold tracking-tight md:text-7xl">A clearer digital classroom.</h1>
                    <p className="text-muted-foreground mx-auto mt-7 max-w-2xl text-lg leading-8">TOH Klas connects teachers, shared student computers, and learning activity—built for real school labs and unreliable connectivity.</p>
                </div>
            </section>
        </main>
    </>;
}
