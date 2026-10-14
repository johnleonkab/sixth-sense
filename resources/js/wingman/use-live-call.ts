import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

import { echoConnection } from '@/lib/echo';
import { getJson, postJson } from '@/lib/http';

export type LiveCall = {
    id: number;
    title: string;
    meeting_url: string;
    joined_at: string | null;
    paused: boolean;
    segment_count: number;
    attendees: string[];
    lines: {
        id: number;
        speaker: string;
        is_me: boolean;
        text: string;
        final: boolean;
    }[];
    issues: {
        ref: string;
        key: string;
        summary: string;
        status: string;
        status_category: string;
        url: string | null;
    }[];
    pages: { ref: string; title: string; url: string | null }[];
    prepared: boolean;
    /** From the living notes: what it is about so far, how many notes, what is still open. */
    summary: string;
    note_count: number;
    open_questions: string[];
};

/** While in a call, it is asked for this often; otherwise, now and then in case an event was missed. */
const WHILE_LIVE = 4_000;
const WHILE_IDLE = 60_000;

/**
 * The call the person is in, if any, kept in step with the server: live
 * events say when a call starts, ends or changes; while one goes on, it is
 * asked for every few seconds for what is being said.
 */
export function useLiveCall(): {
    call: LiveCall | null;
    setPaused: (paused: boolean) => Promise<void>;
} {
    const { auth } = usePage().props;
    const [call, setCall] = useState<LiveCall | null>(null);

    const refresh = useCallback(async () => {
        try {
            setCall((await getJson<{ meeting: LiveCall | null }>('/wingman/live')).meeting);
        } catch {
            // Offline or signed out for a moment: the next tick asks again.
        }
    }, []);

    useEffect(() => {
        if (!auth.user) {
            return;
        }

        void refresh();

        const channel = echoConnection().private(`App.Models.User.${auth.user.id}`);
        const events = ['.wingman.meeting.started', '.wingman.meeting.ended', '.wingman.meeting.updated'];
        events.forEach((event) => channel.listen(event, () => void refresh()));

        return () => events.forEach((event) => channel.stopListening(event));
    }, [auth.user?.id, refresh]);

    const live = call !== null;

    useEffect(() => {
        if (!auth.user) {
            return;
        }

        const timer = window.setInterval(() => document.visibilityState === 'visible' && void refresh(), live ? WHILE_LIVE : WHILE_IDLE);

        return () => window.clearInterval(timer);
    }, [auth.user, live, refresh]);

    const setPaused = useCallback(
        async (paused: boolean) => {
            if (!call) {
                return;
            }

            setCall({ ...call, paused });
            setCall((await postJson<{ meeting: LiveCall }>(`/wingman/${call.id}/pause`, { paused })).meeting);
        },
        [call],
    );

    return { call, setPaused };
}
