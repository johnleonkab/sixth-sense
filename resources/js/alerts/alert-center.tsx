import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { ring } from '@/alerts/sounds';
import type { Alert } from '@/alerts/types';
import { echoConnection } from '@/lib/echo';

const VISIBLE_FOR = 12000;

export const sourceDot: Record<Alert['source'], string> = {
    gmail: 'bg-gmail',
    slack: 'bg-slack',
    calendar: 'bg-accent',
};

const timeFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

type AlertCenterProps = {
    /** Alerts waiting for an answer when the page loaded: they stay until answered. */
    pending?: Alert[];
    /** Open the alert on screen (clicking it). */
    onOpen?: (alert: Alert) => void;
    /** An alert arrived (e.g. to give the voice assistant context). */
    onArrive?: (alert: Alert) => void;
    /** The person wants the assistant to tell them about it. */
    onTellMe?: (alert: Alert) => void;
    /** Whether the assistant is listening, so saying "dime" is enough. */
    listening?: boolean;
};

/**
 * Listens for alerts the backend raises: rings their sound and shows each
 * for a few seconds, as in docs/prototype.html. Those waiting for an answer
 * stay listed until the person answers them, here or in the service itself.
 */
export default function AlertCenter({ pending: initial = [], onOpen, onArrive, onTellMe, listening = false }: AlertCenterProps) {
    const { auth } = usePage().props;
    const [alert, setAlert] = useState<Alert | null>(null);
    const [pending, setPending] = useState<Alert[]>(initial);
    const [listOpen, setListOpen] = useState(false);
    const hideTimer = useRef<number | undefined>(undefined);
    const onArriveRef = useRef(onArrive);
    onArriveRef.current = onArrive;

    useEffect(() => {
        if (!auth.user) {
            return;
        }

        const channel = echoConnection().private(`App.Models.User.${auth.user.id}`);

        // Newest first; a conversation that got a new message moves to the top.
        const keep = (incoming: Alert) =>
            setPending((list) => (incoming.status === 'pending' ? [incoming, ...list.filter((item) => item.id !== incoming.id)] : list.filter((item) => item.id !== incoming.id)));

        channel.listen('.inbox.item.created', (incoming: Alert) => {
            ring(incoming.source);
            setAlert(incoming);
            keep(incoming);
            onArriveRef.current?.(incoming);
            window.clearTimeout(hideTimer.current);
            hideTimer.current = window.setTimeout(() => setAlert(null), VISIBLE_FOR);
        });

        // A proposal is ready, a new message joined it, or it was answered: update quietly.
        channel.listen('.inbox.item.updated', (updated: Alert) => {
            setPending((list) =>
                updated.status === 'pending'
                    ? list.some((item) => item.id === updated.id)
                        ? list.map((item) => (item.id === updated.id ? updated : item))
                        : [updated, ...list]
                    : list.filter((item) => item.id !== updated.id),
            );
            setAlert((shown) => (shown?.id === updated.id ? (updated.status === 'pending' ? updated : null) : shown));
        });

        return () => {
            channel.stopListening('.inbox.item.created');
            channel.stopListening('.inbox.item.updated');
            window.clearTimeout(hideTimer.current);
        };
    }, [auth.user?.id]);

    const open = (target: Alert) => {
        setAlert(null);
        setListOpen(false);

        if (onOpen) {
            onOpen(target);
        } else if (target.external_url) {
            window.open(target.external_url, '_blank', 'noopener');
        }
    };

    return (
        <>
            {pending.length > 0 && onOpen && (
                <div className="fixed top-[60px] right-4 z-40">
                    <button
                        type="button"
                        aria-expanded={listOpen}
                        onClick={() => setListOpen(!listOpen)}
                        className="ml-auto flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1.5 text-[12.5px] shadow-card hover:bg-soft"
                    >
                        <i aria-hidden className="size-[7px] rounded-full bg-accent" />
                        {pending.length === 1 ? '1 pendiente' : `${pending.length} pendientes`}
                    </button>

                    {listOpen && (
                        <ol
                            aria-label="Pendientes de responder"
                            className="mt-2 max-h-[60vh] w-[min(380px,calc(100vw-32px))] overflow-auto rounded-[14px] border border-line bg-surface p-1.5 shadow-card"
                        >
                            {pending.map((item) => (
                                <li key={item.id}>
                                    <button
                                        type="button"
                                        onClick={() => open(item)}
                                        className="grid w-full grid-cols-[auto_minmax(0,1fr)] gap-x-2.5 rounded-[10px] px-2.5 py-2 text-left hover:bg-soft"
                                    >
                                        <i aria-hidden className={`mt-1.5 size-[8px] rounded-[3px] ${sourceDot[item.source]}`} />
                                        <span className="min-w-0">
                                            <span className="flex justify-between gap-2 text-[11.5px] text-muted">
                                                <span className="truncate">
                                                    {item.actor_name} · {item.title}
                                                </span>
                                                <time className="flex-none font-mono">{timeFormat.format(new Date(item.received_at))}</time>
                                            </span>
                                            <span className="line-clamp-2 block text-[13px]">{item.why}</span>
                                            {item.proposal?.summary && <span className="mt-0.5 line-clamp-1 block text-[12px] text-accent">→ {item.proposal.summary}</span>}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            )}

            {alert && (
                <div role="status" aria-live="polite" className="alert-in fixed top-[60px] right-4 z-50 w-[min(360px,calc(100%-32px))] overflow-hidden rounded-[14px] border border-line bg-surface shadow-card">
                    <button
                        type="button"
                        onClick={() => open(alert)}
                        className="grid w-full grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 px-4 py-3.5 text-left hover:bg-soft"
                    >
                        <i aria-hidden className={`mt-1.5 size-[9px] rounded-[3px] ${sourceDot[alert.source]}`} />
                        <div className="min-w-0">
                            <div className="flex justify-between gap-2 text-[12px] text-muted">
                                <span className="truncate">
                                    {alert.source_label} · {alert.actor_name}
                                </span>
                                <time className="flex-none font-mono">{timeFormat.format(new Date(alert.received_at))}</time>
                            </div>
                            <b className="block truncate text-[13.5px] font-semibold">{alert.title}</b>
                            <p className="mt-px line-clamp-2 text-[13px] text-muted">{alert.why}</p>
                        </div>
                    </button>

                    {(onTellMe || alert.meeting_url) && (
                        <div className="flex items-center justify-end gap-2 border-t border-line px-4 py-2 text-[12.5px]">
                            {alert.meeting_url && (
                                <a
                                    href={alert.meeting_url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    onClick={() => setAlert(null)}
                                    className="mr-auto rounded-full border border-line px-3 py-1 font-medium text-accent hover:bg-accent-soft"
                                >
                                    Unirse
                                </a>
                            )}
                            {!onTellMe ? null : listening ? (
                                <span className="text-muted">
                                    Di <b className="font-medium text-ink">«dime»</b> y te lo cuento
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setAlert(null);
                                        onTellMe(alert);
                                    }}
                                    className="rounded-full bg-ink px-3 py-1 font-medium text-bg hover:opacity-90"
                                >
                                    Dime
                                </button>
                            )}
                        </div>
                    )}
                </div>
            )}
        </>
    );
}
