import type { ReactNode } from 'react';

import Switch from '@/components/switch';

export function joinEs(parts: string[]): string {
    if (parts.length === 0) {
        return '';
    }

    if (parts.length === 1) {
        return parts[0];
    }

    return `${parts.slice(0, -1).join(', ')} y ${parts[parts.length - 1]}`;
}

type PlanStepProps = {
    n?: number;
    kind: string;
    included?: boolean;
    onToggle?: () => void;
    children?: ReactNode;
};

/**
 * One proposed action, always the same shape: number, what it is, optional
 * include switch, then the draft or detail.
 */
export function PlanStep({ n, kind, included, onToggle, children }: PlanStepProps) {
    return (
        <div className="rounded-2xl border border-line bg-soft/80 px-4 py-3.5">
            <div className="flex min-h-11 items-center gap-3">
                {n != null && (
                    <span className="grid size-7 flex-none place-items-center rounded-full bg-ink text-[13px] font-medium text-bg">{n}</span>
                )}
                <p className="min-w-0 flex-1 text-[16px] leading-[1.3] font-medium md:text-[14.5px]">{kind}</p>
                {onToggle && (
                    <Switch checked={included !== false} onChange={() => onToggle()} className="flex-none text-[14px] text-muted">
                        Incluir
                    </Switch>
                )}
            </div>
            {children && <div className="mt-2.5">{children}</div>}
        </div>
    );
}

/**
 * Action buttons for an open alert. On a phone they stick above the voice
 * dock, full width and large enough to tap.
 */
export function AlertCtaBar({ children }: { children: ReactNode }) {
    return (
        <div className="mt-6 flex flex-col gap-2.5 max-md:sticky max-md:bottom-[var(--voice-dock-height,9rem)] max-md:z-20 max-md:-mx-4 max-md:border-t max-md:border-line max-md:bg-surface max-md:px-4 max-md:pt-3 max-md:pb-[max(0.75rem,env(safe-area-inset-bottom))] max-md:[&_button]:w-full md:mt-5 md:flex-row md:flex-wrap md:items-center">
            {children}
        </div>
    );
}
