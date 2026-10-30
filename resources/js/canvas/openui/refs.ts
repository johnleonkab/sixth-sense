import { createContext } from 'react';

/** How to open the item numbered n of the view's lists: a tool and its arguments. */
export type OpenableItem = { n: number; title: string; tool: string; args: Record<string, unknown> };

/**
 * What a ref in a view does, without the assistant: "alert:42" reviews alert
 * 42, "day:2026-10-30" shows that day, "correct:7:not_important" proposes a
 * Slack rule. Only these tools: they read, propose a change that waits for
 * the "sí", or make the assistant more careful (lowering its autonomy, taking
 * off the calendar what the scheduler itself put there). Anything else is
 * not run.
 */
const refs: Record<string, (id: string) => Pick<OpenableItem, 'tool' | 'args'> | null> = {
    alert: (id) => ({ tool: 'review_alert', args: { alert_id: Number(id) } }),
    day: (date) => ({ tool: 'show_calendar', args: { start_date: date } }),
    'slack-message': (ref) => ({ tool: 'show_slack_conversation', args: { message_id: ref } }),
    correct: (rest) => {
        const [decision, correction] = rest.split(':');

        return ['not_important', 'someone_else', 'always_notify'].includes(correction)
            ? { tool: 'correct_slack_triage', args: { decision_id: Number(decision), correction } }
            : null;
    },
    'autonomy-raise': (action) => ({ tool: 'propose_slack_autonomy', args: { action } }),
    'autonomy-lower': (rest) => {
        const [action, level] = rest.split(':');

        return { tool: 'lower_slack_autonomy', args: { action, level } };
    },
    'task-cancel': (id) => ({ tool: 'cancel_scheduled_task', args: { task_id: Number(id) } }),
};

export function openRef(ref: string | undefined): Pick<OpenableItem, 'tool' | 'args'> | null {
    const [kind, id] = (ref ?? '').split(/:(.*)/s, 2);

    return kind && id && refs[kind] ? refs[kind](id) : null;
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
