import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="toh-pattern-bg flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col items-center gap-6">
                    <Link href={home()} className="flex flex-col items-center gap-2 font-medium">
                        <span className="bg-primary text-primary-foreground grid size-12 place-items-center rounded-xl shadow-sm">
                            <AppLogoIcon className="size-6 fill-current" />
                        </span>
                        <span className="sr-only">{title}</span>
                    </Link>

                    {/* The card separates the form from the bold blob background behind it —
                        without it, fields float directly on the pattern with no visual anchor. */}
                    <div
                        className="bg-background w-full rounded-2xl border p-8 shadow-lg
                            [&_input]:h-11 [&_input]:rounded-lg
                            [&_button[type=submit]]:h-11 [&_button[type=submit]]:rounded-full"
                    >
                        <div className="mb-6 space-y-2 text-center">
                            <h1 className="text-xl font-semibold">{title}</h1>
                            <p className="text-muted-foreground text-center text-sm">
                                {description}
                            </p>
                        </div>
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
