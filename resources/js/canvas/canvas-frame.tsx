import type { ReactNode } from 'react';

import { ServiceIconFor } from '@/components/service-icon';

type CanvasFrameProps = {
    index: number;
    title: string;
    source: string;
    aside?: ReactNode;
    children: ReactNode;
};

/**
 * Card every canvas is drawn in, as in docs/prototype.html: its number, a
 * title and where the data comes from.
 */
export default function CanvasFrame({ index, title, source, aside, children }: CanvasFrameProps) {
    return (
        <article className="canvas-in min-w-0 rounded-2xl border border-line bg-surface">
            <header className="flex flex-col gap-2 px-4 pt-4 md:flex-row md:items-center md:gap-2.5 md:px-[22px] md:pt-[18px]">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span
                        aria-label={`Número ${index}`}
                        className="grid size-5 flex-none place-items-center rounded-full border border-line font-mono text-[11px] text-muted"
                    >
                        {index}
                    </span>
                    <h3 className="min-w-0 truncate text-[15px] font-medium tracking-[-0.01em]">{title}</h3>
                </div>
                <span className="flex min-w-0 flex-wrap items-center gap-2 text-[12px] text-muted md:ml-auto md:flex-none md:whitespace-nowrap">
                    {aside}
                    <span className="flex items-center gap-1.5">
                        <ServiceIconFor reference={source} size={14} label={false} />
                        {source}
                    </span>
                </span>
            </header>

            <div className="overflow-x-auto px-4 pt-4 pb-[22px] md:px-[22px]">{children}</div>
        </article>
    );
}
