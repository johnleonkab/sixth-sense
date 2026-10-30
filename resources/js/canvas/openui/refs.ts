import { createContext } from 'react';

/** How to open the item numbered n of the view's lists: a tool and its arguments. */
export type OpenableItem = { n: number; title: string; tool: string; args: Record<string, unknown> };

/**
 * How an item that came from a Query opens, from the ref its row carries:
 * "alert:42" reviews alert 42, "day:2026-10-30" shows that day. Only these
 * tools, which only read; anything else is not opened.
 */
const opens: Record<string, (id: string) => Pick<OpenableItem, 'tool' | 'args'>> = {
    alert: (id) => ({ tool: 'review_alert', args: { alert_id: Number(id) } }),
    day: (date) => ({ tool: 'show_calendar', args: { start_date: date } }),
};

export function openRef(ref: string | undefined): Pick<OpenableItem, 'tool' | 'args'> | null {
    const [kind, id] = (ref ?? '').split(/:(.*)/s, 2);

    return kind && id && opens[kind] ? opens[kind](id) : null;
}

/** Items on screen that came from a Query, by canvas number, for "abre el 2". */
const live = new Map<number, OpenableItem[]>();

export function setLiveItems(index: number, items: OpenableItem[] | null): void {
    if (items === null) {
        live.delete(index);
    } else {
        live.set(index, items);
    }
}

export function liveItems(index: number): OpenableItem[] | null {
    return live.get(index) ?? null;
}

/** Lets a list say which items it shows now, so the canvas can tell the assistant. */
export const LiveItemsContext = createContext<((items: OpenableItem[]) => void) | null>(null);
