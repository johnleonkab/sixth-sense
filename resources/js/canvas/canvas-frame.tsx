import { useContext } from 'react';
import type { ReactNode } from 'react';

import { EmbeddedContext } from '@/canvas/embedded';
import { ServiceIconFor } from '@/components/service-icon';

type CanvasFrameProps = {
    index: number;
    title: string;
    source: string;
    aside?: ReactNode;
    /** When false, the card is recognized by its icon, not by a title. */
    showTitle?: boolean;
    /** When false, the source is only its icon. */
    showSourceName?: boolean;
    /** Service icons when one card spans more than one channel. */
    marks?: string[];
    children: ReactNode;
};

/**
 * Card every canvas is drawn in, as in docs/prototype.html: its number, a
 * title and where the data comes from.
 */
export default function CanvasFrame({ index, title, source, aside, showTitle = true, showSourceName = true, marks, children }: CanvasFrameProps) {
    const references = marks?.length ? marks : [source];
    const sourceMark = (
        <span className="flex items-center gap-1.5">
            {references.map((reference) => (
                <ServiceIconFor key={reference} reference={reference} size={16} label={showSourceName ? false : undefined} />
            ))}
            {showSourceName && source}
        </span>
    );

    // Inside a composed view, beside another detail: the view already has the number.
    if (useContext(EmbeddedContext)) {
        return (
            <article className="min-w-0 rounded-xl border border-line bg-bg">
                <header className="flex items-center gap-2 px-3.5 pt-3 text-[12px] text-muted">
                    <span className="flex items-center gap-1">
                        {references.map((reference) => (
                            <ServiceIconFor key={reference} reference={reference} size={13} label={false} />
                        ))}
                    </span>
                    {showTitle && <h4 className="min-w-0 flex-1 truncate text-[13.5px] font-medium text-ink">{title}</h4>}
                    {aside}
                </header>
                <div className="overflow-x-auto px-3.5 pt-3 pb-4">{children}</div>
            </article>
        );
    }

    return (
        <article className="canvas-in min-w-0 rounded-2xl border border-line bg-surface">
            <header className="flex flex-col gap-2 px-4 pt-4 md:flex-row md:items-center md:gap-2.5 md:px-[22px] md:pt-[18px]">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span
                        aria-label={`Número ${index}`}
                        className="grid size-6 flex-none place-items-center rounded-full border border-line font-mono text-[13px] text-muted md:size-5 md:text-[11px]"
                    >
                        {index}
                    </span>
                    {showTitle ? (
                        <h3 className="min-w-0 truncate text-[17px] font-medium tracking-[-0.01em] md:text-[15px]">{title}</h3>
                    ) : (
                        sourceMark
                    )}
                </div>
                {(aside || showTitle) && (
                    <span className="flex min-w-0 flex-wrap items-center gap-2 text-[14px] text-muted md:ml-auto md:flex-none md:text-[12px] md:whitespace-nowrap">
                        {aside}
                        {showTitle && sourceMark}
                    </span>
                )}
            </header>

            <div className="overflow-x-auto px-4 pt-4 pb-[22px] md:px-[22px]">{children}</div>
        </article>
    );
}
