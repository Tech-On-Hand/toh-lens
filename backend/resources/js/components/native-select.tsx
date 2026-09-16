import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * A plain HTML <select>, styled to match the shadcn Input, so it serializes
 * with Inertia's <Form> the same way any other named field does — the
 * Radix Select doesn't render a native form control, which would need extra
 * controlled-state wiring for every dropdown in these admin forms.
 */
function NativeSelect({ className, children, ...props }: React.ComponentProps<'select'>) {
    return (
        <select
            data-slot="native-select"
            className={cn(
                'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        >
            {children}
        </select>
    );
}

export { NativeSelect };
