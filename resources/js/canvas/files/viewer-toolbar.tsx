import type { ReactNode } from 'react';

/** A file someone sent, served by Laravel with the user's credentials. */
export type FileProps = {
    name: string;
    mime_type: string | null;
    size: number;
    source: string;
    src: string;
};

const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4];

/** The next zoom step up (1) or down (-1); 1 fits the width. */
export function nextZoom(current: number, direction: 1 | -1): number {
    const steps = direction === 1 ? ZOOMS.filter((zoom) => zoom > current + 0.001) : ZOOMS.filter((zoom) => zoom < current - 0.001).reverse();

    return steps[0] ?? current;
}

export function ToolbarButton({ label, disabled, onClick, children }: { label: string; disabled?: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className="grid h-7 min-w-7 place-items-center rounded-md px-1.5 text-[13px] text-ink hover:bg-soft disabled:text-faint disabled:hover:bg-transparent"
        >
            {children}
        </button>
    );
}
