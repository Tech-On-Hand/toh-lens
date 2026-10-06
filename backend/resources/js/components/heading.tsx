import type { LucideIcon } from 'lucide-react';

export default function Heading({
    title,
    description,
    icon: Icon,
    accent,
    variant = 'default',
}: {
    title: string;
    description?: string;
    icon?: LucideIcon;
    /** A TOH CSS custom property (e.g. '--toh-blue') to tint the icon badge with. Defaults to navy/primary. */
    accent?: string;
    variant?: 'default' | 'small';
}) {
    const badgeSizeClass = variant === 'small'
        ? 'flex size-6 shrink-0 items-center justify-center rounded-md'
        : 'flex size-8 shrink-0 items-center justify-center rounded-lg';
    const badgeStyle = accent
        ? { background: `color-mix(in srgb, var(${accent}) 12%, white)`, color: `var(${accent})` }
        : undefined;

    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <h2
                className={
                    variant === 'small'
                        ? 'mb-0.5 flex items-center gap-2 text-base font-medium'
                        : 'flex items-center gap-2.5 text-xl font-semibold tracking-tight'
                }
            >
                {Icon && (
                    <span className={accent ? badgeSizeClass : `bg-primary/10 text-primary ${badgeSizeClass}`} style={badgeStyle}>
                        <Icon className={variant === 'small' ? 'size-3.5' : 'size-4.5'} />
                    </span>
                )}
                {title}
            </h2>
            {description && (
                <p className="text-muted-foreground text-sm">{description}</p>
            )}
        </header>
    );
}
