import type { ReactNode } from 'react';

const deadlineFormat = new Intl.DateTimeFormat('es-ES', { weekday: 'short', day: 'numeric', month: 'short' });

function deadlineLine(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `antes del ${deadlineFormat.format(date)}`;
}

type AlertCalloutProps = {
    /** What the person is being asked to do. */
    ask: string;
    actor?: string | null;
    source: string;
    where?: string | null;
    deadline?: string | null;
    children?: ReactNode;
};

/**
 * The ask, first and largest: who needs what from the person. Conversation
 * and the plan come after.
 */
export default function AlertCallout({ ask, actor, source, where, deadline, children }: AlertCalloutProps) {
    const due = deadlineLine(deadline);
    const meta = [actor || null, source, where || null, due].filter((part): part is string => Boolean(part));

    return (
        <section aria-label="Qué te piden" className="rounded-2xl border border-accent/35 bg-accent-soft px-4 py-4 md:px-5">
            <p className="text-[13px] font-medium tracking-wide text-accent uppercase">Te piden</p>
            <p className="mt-1.5 text-[20px] leading-[1.3] font-semibold tracking-[-0.02em] text-ink md:text-[18px] md:leading-[1.35]">{ask}</p>
            {meta.length > 0 && (
                <p className="mt-2.5 text-[15px] leading-[1.4] text-muted md:text-[13.5px]">
                    {actor ? (
                        <>
                            <b className="font-medium text-ink">{actor}</b>
                            {meta.length > 1 && <span> · {meta.slice(1).join(' · ')}</span>}
                        </>
                    ) : (
                        meta.join(' · ')
                    )}
                </p>
            )}
            {children}
        </section>
    );
}
