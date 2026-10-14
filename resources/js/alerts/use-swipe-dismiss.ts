import { type CSSProperties, type MouseEvent, type PointerEvent, useRef, useState } from 'react';

/** Dragged this far to the right (or flicked fast enough), it goes. */
const DISTANCE = 96;
const SPEED = 0.6;
/** Moving less than this is a click, not a drag. */
const SLOP = 6;

type Swipe = {
    /** Spread on the element that is dragged. */
    handlers: {
        onPointerDown: (event: PointerEvent<HTMLElement>) => void;
        onPointerMove: (event: PointerEvent<HTMLElement>) => void;
        onPointerUp: (event: PointerEvent<HTMLElement>) => void;
        onPointerCancel: () => void;
        onClickCapture: (event: MouseEvent<HTMLElement>) => void;
    };
    style: CSSProperties;
    /** Leaving, after a swipe or the dismiss button. */
    leaving: boolean;
    dismiss: () => void;
};

/**
 * Swipe right to dismiss, as with notifications: it follows the finger or
 * the mouse, goes back if not dragged far enough, and slides out if it is.
 * A drag never counts as a click on what is underneath.
 */
export function useSwipeToDismiss(onDismiss: () => void): Swipe {
    const [offset, setOffset] = useState(0);
    const [dragging, setDragging] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const start = useRef<{ x: number; at: number } | null>(null);
    const moved = useRef(false);

    const dismiss = () => {
        if (leaving) {
            return;
        }

        setLeaving(true);
        window.setTimeout(onDismiss, 180);
    };

    return {
        leaving,
        dismiss,
        style: {
            transform: leaving ? 'translateX(110%)' : offset ? `translateX(${offset}px)` : undefined,
            opacity: leaving ? 0 : offset ? Math.max(0.25, 1 - offset / 260) : undefined,
            transition: dragging ? 'none' : 'transform 180ms ease, opacity 180ms ease',
            touchAction: 'pan-y',
        },
        handlers: {
            onPointerDown: (event) => {
                if (event.button !== 0) {
                    return;
                }

                start.current = { x: event.clientX, at: event.timeStamp };
                moved.current = false;
            },
            onPointerMove: (event) => {
                if (!start.current) {
                    return;
                }

                const distance = event.clientX - start.current.x;

                if (!moved.current && Math.abs(distance) > SLOP) {
                    moved.current = true;
                    setDragging(true);
                    event.currentTarget.setPointerCapture(event.pointerId);
                }

                if (moved.current) {
                    // Only to the right, off the edge of the screen; a little resistance to the left.
                    setOffset(distance > 0 ? distance : distance / 6);
                }
            },
            onPointerUp: (event) => {
                if (!start.current) {
                    return;
                }

                const distance = event.clientX - start.current.x;
                const speed = distance / Math.max(1, event.timeStamp - start.current.at);
                start.current = null;
                setDragging(false);

                if (moved.current && (distance > DISTANCE || speed > SPEED)) {
                    dismiss();
                } else {
                    setOffset(0);
                }
            },
            onPointerCancel: () => {
                start.current = null;
                setDragging(false);
                setOffset(0);
            },
            onClickCapture: (event) => {
                // The click that ends a drag opens nothing.
                if (moved.current) {
                    event.preventDefault();
                    event.stopPropagation();
                    moved.current = false;
                }
            },
        },
    };
}
