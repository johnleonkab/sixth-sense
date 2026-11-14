import type { ReactNode } from 'react';

import { ServiceIconFor } from '@/components/service-icon';

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

export function isHollowAsk(value: string | null | undefined): boolean {
    const text = value?.trim() ?? '';

    return text === '' || /unspecified|sin especificar|no especificado/i.test(text);
}

type AlertCalloutProps = {
    /** What the person is being asked, or the words that were written when that is unknown. */
    ask: string | null;
    /** The ask is the message itself, not a summary that names the task. */
    quoted?: boolean;
    people?: string[];
    sources?: string[];
    actor?: string | null;
    source?: string | null;
    where?: string | null;
    deadline?: string | null;
    children?: ReactNode;
};

/**
 * Who is involved and, when it is known, what they need. A summary that does
 * not name the task is left out: the conversation underneath is the request.
 */
export default function AlertCallout({ ask, quoted = false, people, sources, actor, source, where, deadline, children }: AlertCalloutProps) {
    const due = deadlineLine(deadline);
    const names = (people?.length ? people : [actor]).filter((name): name is string => Boolean(name?.trim()));
    const marks = sources?.length ? sources : source ? [source] : [];
    const shown = ask && !isHollowAsk(ask) ? ask : quoted ? ask : null;

    return (
        <section aria-label={shown ?? 'Aviso'} className="rounded-2xl border border-accent/35 bg-accent-soft px-4 py-4 md:px-5">
            {(marks.length > 0 || names.length > 0 || due || where) && (
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[15px] leading-[1.4] md:text-[13.5px]">
                    {marks.length > 0 && (
                        <span className="inline-flex items-center gap-1">
                            {marks.map((mark) => (
                                <ServiceIconFor key={mark} reference={mark} size={15} />
                            ))}
                        </span>
                    )}
                    {names.length > 0 && <b className="font-medium text-ink">{names.join(', ')}</b>}
                    {due && <span className="text-muted">{names.length > 0 || marks.length > 0 ? `· ${due}` : due}</span>}
                    {where && <span className="text-muted">· {where}</span>}
                </p>
            )}
            {shown && (
                <p
                    className={
                        quoted
                            ? 'mt-3 text-[16px] leading-[1.5] whitespace-pre-wrap text-ink md:text-[14.5px]'
                            : 'mt-2 text-[20px] leading-[1.3] font-semibold tracking-[-0.02em] text-ink md:text-[18px] md:leading-[1.35]'
                    }
                >
                    {shown}
                </p>
            )}
            {children}
        </section>
    );
}
