import type { ReactNode } from 'react';

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
            <header className="flex items-center gap-2.5 px-[22px] pt-[18px]">
                <span
                    aria-label={`Número ${index}`}
                    className="grid size-5 flex-none place-items-center rounded-full border border-line font-mono text-[11px] text-muted"
                >
                    {index}
                </span>
                <h3 className="min-w-0 truncate text-[15px] font-medium tracking-[-0.01em]">{title}</h3>
                <span className="ml-auto flex flex-none items-center gap-2 text-[12px] whitespace-nowrap text-muted">
                    {aside}
                    {source}
                </span>
            </header>

            <div className="px-[22px] pt-4 pb-[22px]">{children}</div>
        </article>
    );
}
