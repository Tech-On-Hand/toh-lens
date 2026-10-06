import type { LucideIcon } from 'lucide-react';

export default function Heading({
    title,
    description,
    icon: Icon,
    variant = 'default',
}: {
    title: string;
    description?: string;
    icon?: LucideIcon;
    variant?: 'default' | 'small';
}) {
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
                    <span
                        className={
                            variant === 'small'
                                ? 'bg-primary/10 text-primary flex size-6 shrink-0 items-center justify-center rounded-md'
                                : 'bg-primary/10 text-primary flex size-8 shrink-0 items-center justify-center rounded-lg'
                        }
                    >
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
