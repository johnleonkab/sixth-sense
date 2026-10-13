import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ring } from '@/alerts/sounds';
import type { Alert } from '@/alerts/types';
import { echoConnection } from '@/lib/echo';
import { getJson } from '@/lib/http';

/** Ask the server for the whole feed this often, in case an event was missed. */
const RESYNC_EVERY = 45_000;

/** Only alerts this recent ring when they become important (not old ones found on a resync). */
const RING_WITHIN = 10 * 60_000;

const RUNG_KEY = 'alerts-rung';

/** States in which an alert waits for the person: these ring, once. */
const IMPORTANT = new Set<Alert['state']>(['important', 'planning', 'ready']);

function rungIds(): Set<number> {
    try {
        return new Set(JSON.parse(sessionStorage.getItem(RUNG_KEY) ?? '[]') as number[]);
    } catch {
        return new Set();
    }
}

function remember(rung: Set<number>): void {
    try {
        sessionStorage.setItem(RUNG_KEY, JSON.stringify([...rung].slice(-200)));
    } catch {
        // Private mode: rings may repeat after a reload, nothing worse.
    }
}

const newestFirst = (a: Alert, b: Alert) => b.received_at.localeCompare(a.received_at);

/** Window event with an alert's latest state, for whatever shows it (an alert on review, for one). */
export const ALERT_CHANGED = 'sixth-sense:alert-changed';

function broadcast(alert: Alert): void {
    window.dispatchEvent(new CustomEvent<Alert>(ALERT_CHANGED, { detail: alert }));
}

export type AlertFeed = {
    items: Alert[];
    /** Whether the live connection is up (when down, the feed still resyncs on its own). */
    live: boolean;
    /** Items that just arrived or changed, to show briefly. */
    fresh: Alert[];
    dismissFresh: (id: number) => void;
};

type Options = {
    /** What the page already had, so the widget shows at once. */
    initial?: Alert[];
    /** Something new came in (to give the voice assistant context). */
    onArrive?: (alert: Alert) => void;
    /** Something became an alert waiting for an answer. */
    onImportant?: (alert: Alert) => void;
};

/**
 * Everything that came in lately and where each stands, kept in step with
 * the server. Live events update it at once; it also asks the server for
 * the whole feed on load, on reconnecting, on coming back to the tab and
 * every little while, so a missed event never leaves it wrong. Only what
 * becomes important rings, once.
 */
export function useAlertFeed({ initial = [], onArrive, onImportant }: Options = {}): AlertFeed {
    const { auth } = usePage().props;
    const [items, setItems] = useState<Alert[]>(() => [...initial].sort(newestFirst));
    const [fresh, setFresh] = useState<Alert[]>([]);
    const [live, setLive] = useState(false);
    const known = useRef(new Map<number, Alert>(initial.map((item) => [item.id, item])));
    const rung = useRef<Set<number>>(rungIds());
    const callbacks = useRef({ onArrive, onImportant });
    callbacks.current = { onArrive, onImportant };

    // What was already there when the page loaded never rings.
    useEffect(() => {
        initial.forEach((item) => IMPORTANT.has(item.state) && rung.current.add(item.id));
        remember(rung.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** Take in one item's latest state: show it, and ring if it just became important. */
    const take = useCallback((incoming: Alert, announce: boolean) => {
        try {
            const previous = known.current.get(incoming.id);
            known.current.set(incoming.id, incoming);

            if (previous && JSON.stringify(previous) !== JSON.stringify(incoming)) {
                broadcast(incoming);
            }

            const recent = Date.now() - new Date(incoming.received_at).getTime() < RING_WITHIN;
            const becameImportant = IMPORTANT.has(incoming.state) && !rung.current.has(incoming.id);

            if (becameImportant) {
                rung.current.add(incoming.id);
                remember(rung.current);

                if (recent) {
                    ring(incoming.source);
                    callbacks.current.onImportant?.(incoming);
                }
            }

            if (incoming.state === 'meeting' && !previous && announce) {
                ring('calendar');
            }

            if (!previous && announce) {
                callbacks.current.onArrive?.(incoming);
            }

            // Closed by the person themselves: they already know. Answered elsewhere: worth a word.
            const closedByThem = (incoming.state === 'resolved' || incoming.state === 'dismissed') && incoming.resolution !== 'replied';

            // New, or newly important, or its state changed: shown briefly.
            if (announce && !closedByThem && (!previous || (becameImportant && recent) || previous.state !== incoming.state)) {
                setFresh((list) => [incoming, ...list.filter((item) => item.id !== incoming.id)].slice(0, 3));
            }
        } catch (error) {
            console.error(error);
        }
    }, []);

    const resync = useCallback(async () => {
        try {
            const { items: latest } = await getJson<{ items: Alert[] }>('/alerts/feed');
            latest.forEach((item) => take(item, Date.now() - new Date(item.received_at).getTime() < RING_WITHIN));
            setItems([...latest].sort(newestFirst));
            setFresh((list) => list.map((item) => latest.find((other) => other.id === item.id) ?? item));
        } catch (error) {
            // Offline or signed out for a moment: the next resync tries again.
            console.warn('Alert feed resync failed', error);
        }
    }, [take]);

    useEffect(() => {
        if (!auth.user) {
            return;
        }

        const upsert = (incoming: Alert) => {
            take(incoming, true);
            setItems((list) => [incoming, ...list.filter((item) => item.id !== incoming.id)].sort(newestFirst));
            setFresh((list) => list.map((item) => (item.id === incoming.id ? incoming : item)));
        };

        const echo = echoConnection();
        const channelName = `App.Models.User.${auth.user.id}`;
        const channel = echo.private(channelName);
        channel.listen('.inbox.item.created', upsert);
        channel.listen('.inbox.item.updated', upsert);
        channel.error(() => window.setTimeout(() => void resync(), 3000));

        // Whatever happened while the connection was down is asked for again.
        const connection = (echo.connector as { pusher?: { connection: { state: string; bind: (event: string, handler: (states: { previous: string; current: string }) => void) => void; unbind: (event: string, handler: unknown) => void } } }).pusher?.connection;
        const onStateChange = ({ current }: { previous: string; current: string }) => {
            setLive(current === 'connected');

            if (current === 'connected') {
                void resync();
            }
        };
        connection?.bind('state_change', onStateChange);
        setLive(connection?.state === 'connected');

        const onVisible = () => document.visibilityState === 'visible' && void resync();
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('online', onVisible);
        const timer = window.setInterval(() => void resync(), RESYNC_EVERY);
        void resync();

        return () => {
            channel.stopListening('.inbox.item.created');
            channel.stopListening('.inbox.item.updated');
            connection?.unbind('state_change', onStateChange);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('online', onVisible);
            window.clearInterval(timer);
        };
    }, [auth.user?.id, resync, take]);

    const dismissFresh = useCallback((id: number) => setFresh((list) => list.filter((item) => item.id !== id)), []);

    return { items, live, fresh, dismissFresh };
}
