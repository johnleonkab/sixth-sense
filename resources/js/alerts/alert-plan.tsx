import type { ReactNode } from 'react';

import Switch from '@/components/switch';

/**
 * Section title drawn as an icon. The words stay for the screen reader and
 * for a hover, because the card should be recognizable without a heading.
 */
export function SectionMark({ label, kind }: { label: string; kind: 'conversation' | 'plan' }) {
    return (
        <h4 title={label} className="mb-4 text-muted">
            <span className="sr-only">{label}</span>
            <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden>
                {kind === 'conversation' ? (
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 5.5h12A2.5 2.5 0 0 1 20.5 8v6.5A2.5 2.5 0 0 1 18 17h-6.2L7 20.2V17H6A2.5 2.5 0 0 1 3.5 14.5V8A2.5 2.5 0 0 1 6 5.5ZM8 10h8M8 13.2h5" />
                ) : (
                    <>
                        <path strokeLinecap="round" d="M11 6h9M11 12h9M11 18h9" />
                        <circle cx="6" cy="6" r="1.6" fill="currentColor" stroke="none" />
                        <circle cx="6" cy="12" r="1.6" fill="currentColor" stroke="none" />
                        <circle cx="6" cy="18" r="1.6" fill="currentColor" stroke="none" />
                        <path strokeLinecap="round" d="M6 7.6v2.8M6 13.6v2.8" />
                    </>
                )}
            </svg>
        </h4>
    );
}

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
 * One action in a sequence. The number and the rail stay, so one step and
 * several steps are the same shape.
 */
export function PlanStep({ n, kind, included, onToggle, children }: PlanStepProps) {
    return (
        <li className="group/step relative grid grid-cols-[28px_minmax(0,1fr)] gap-x-3 pb-6 last:pb-0">
            <div className="relative flex justify-center">
                <span className="relative z-10 grid size-7 place-items-center rounded-full bg-ink text-[13px] font-medium text-bg">{n}</span>
                <span aria-hidden className="absolute top-7 bottom-[-1.5rem] left-1/2 w-px -translate-x-1/2 bg-line group-last/step:hidden" />
            </div>
            <div className="min-w-0">
                <div className="flex min-h-7 items-center gap-3">
                    <p className="min-w-0 flex-1 text-[16px] leading-[1.3] font-medium md:text-[14.5px]">{kind}</p>
                    {onToggle && (
                        <Switch checked={included !== false} onChange={() => onToggle()} className="flex-none text-[14px] text-muted">
                            Incluir
                        </Switch>
                    )}
                </div>
                {children && <div className="mt-2.5">{children}</div>}
            </div>
        </li>
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
