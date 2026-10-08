import { useCallback, useEffect, useRef, useState } from 'react';

import { isFinished, type ActionState } from '@/actions/types';
import { postJson } from '@/lib/http';

export type ActionsStore = {
    actions: Record<number, ActionState>;
    track: (action: ActionState) => void;
    confirm: (id: number) => Promise<void>;
    cancel: (id: number) => Promise<void>;
    undo: (id: number) => Promise<void>;
    /** The newest proposal still waiting for an answer. */
    awaitingAnswer: () => ActionState | null;
    /** The newest confirmed action that can still be undone. */
    undoable: () => ActionState | null;
};

const POLL_INTERVAL = 1000;
const MAX_POLLS = 45;

/**
 * Proposed changes on screen and their state. While one is confirmed it polls
 * until it is applied, undone or fails.
 */
export function useActions({ onExecuted }: { onExecuted?: (action: ActionState) => void } = {}): ActionsStore {
    const [actions, setActions] = useState<Record<number, ActionState>>({});
    const latest = useRef<Record<number, ActionState>>({});
    const polling = useRef(new Set<number>());
    const onExecutedRef = useRef(onExecuted);
    onExecutedRef.current = onExecuted;

    const save = useCallback((action: ActionState) => {
        const before = latest.current[action.id]?.status;
        latest.current = { ...latest.current, [action.id]: action };
        setActions(latest.current);

        // The confirmation the person hears: the service really did it.
        if (action.status === 'executed' && before !== undefined && before !== 'executed') {
            onExecutedRef.current?.(action);
        }
    }, []);

    const poll = useCallback(
        async (id: number) => {
            if (polling.current.has(id)) {
                return;
            }

            polling.current.add(id);

            try {
                for (let i = 0; i < MAX_POLLS; i++) {
                    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL));
                    const response = await fetch(`/actions/${id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

                    if (!response.ok) {
                        break;
                    }

                    const action = (await response.json()) as ActionState;
                    save(action);

                    if (isFinished(action.status)) {
                        break;
                    }
                }
            } finally {
                polling.current.delete(id);
            }
        },
        [save],
    );

    const answer = useCallback(
        async (id: number, verb: 'confirm' | 'cancel' | 'undo') => {
            try {
                const action = await postJson<ActionState>(`/actions/${id}/${verb}`, {});
                save(action);

                if (!isFinished(action.status)) {
                    void poll(id);
                }
            } catch (error) {
                console.error(error);
            }
        },
        [poll, save],
    );

    const newest = (match: (action: ActionState) => boolean): ActionState | null =>
        Object.values(latest.current)
            .filter(match)
            .sort((a, b) => b.id - a.id)[0] ?? null;

    useEffect(() => () => polling.current.clear(), []);

    return {
        actions,
        track: save,
        confirm: (id) => answer(id, 'confirm'),
        cancel: (id) => answer(id, 'cancel'),
        undo: (id) => answer(id, 'undo'),
        awaitingAnswer: () => newest((action) => action.status === 'pending' && Date.parse(action.expires_at) > Date.now()),
        undoable: () =>
            newest((action) => action.status === 'confirmed' && action.undo_until !== null && Date.parse(action.undo_until) > Date.now()),
    };
}
