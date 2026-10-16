import { useEffect, useRef, type ReactNode, type RefObject } from 'react';

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

/** Pinch on a document zooms that document. One finger still scrolls it. */
export function usePinchZoom(target: RefObject<HTMLElement | null>, onZoom: (direction: 1 | -1) => void): void {
    const zoom = useRef(onZoom);
    zoom.current = onZoom;

    useEffect(() => {
        const element = target.current;

        if (!element) {
            return;
        }

        let last = 0;

        const distance = (event: TouchEvent): number => {
            const [first, second] = [event.touches[0], event.touches[1]];

            return Math.hypot(first.clientX - second.clientX, first.clientY - second.clientY);
        };

        const start = (event: TouchEvent) => {
            if (event.touches.length === 2) {
                last = distance(event);
            }
        };

        const move = (event: TouchEvent) => {
            if (event.touches.length !== 2 || last === 0) {
                return;
            }

            event.preventDefault();
            const next = distance(event);

            if (next / last >= 1.12) {
                zoom.current(1);
                last = next;
            } else if (next / last <= 0.88) {
                zoom.current(-1);
                last = next;
            }
        };

        const end = () => {
            last = 0;
        };

        element.addEventListener('touchstart', start, { passive: true });
        element.addEventListener('touchmove', move, { passive: false });
        element.addEventListener('touchend', end);
        element.addEventListener('touchcancel', end);

        return () => {
            element.removeEventListener('touchstart', start);
            element.removeEventListener('touchmove', move);
            element.removeEventListener('touchend', end);
            element.removeEventListener('touchcancel', end);
        };
    }, [target]);
}

export function ToolbarButton({ label, disabled, onClick, children }: { label: string; disabled?: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className="grid h-7 min-w-7 place-items-center rounded-md px-1.5 text-[13px] text-ink hover:bg-soft max-md:h-11 max-md:min-w-11 disabled:text-faint disabled:hover:bg-transparent"
        >
            {children}
        </button>
    );
}
