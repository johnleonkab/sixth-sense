import { useEffect, useRef, useState } from 'react';

import type { Alert } from '@/alerts/types';
import { useAlertFeed } from '@/alerts/use-alert-feed';
import WingmanBubble from '@/wingman/wingman-bubble';

export const sourceDot: Record<Alert['source'], string> = {
    gmail: 'bg-gmail',
    slack: 'bg-slack',
    calendar: 'bg-accent',
    agenda: 'bg-ok',
    jira: 'bg-jira',
};

const timeFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

const taskTime = new Intl.DateTimeFormat('es-ES', { weekday: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

const WAITING = new Set<Alert['state']>(['important', 'planning', 'ready']);

/** How long a toast stays, by where its alert stands. Still judging: until it is judged. */
const TOAST_FOR: Partial<Record<Alert['state'], number>> = {
    routine: 6000,
    meeting: 12000,
    important: 14000,
    planning: 14000,
    ready: 14000,
    resolved: 4000,
    dismissed: 4000,
};

const JUDGING_AT_MOST = 30000;

/** Where an alert stands, as a small label: what it is, and whether work is going on. */
export function StateChip({ state }: { state: Alert['state'] }) {
    const base = 'inline-flex flex-none items-center gap-1 rounded-full px-2 py-[2px] text-[10.5px] font-medium whitespace-nowrap';

    switch (state) {
        case 'judging':
            return (
                <span className={`${base} alerts-shimmer text-muted`}>
                    <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-muted" />
                    Analizando
                </span>
            );
        case 'important':
            return <span className={`${base} bg-accent text-accent-ink`}>Importante</span>;
        case 'planning':
            return (
                <span className={`${base} border border-accent/40 bg-accent-soft text-accent`}>
                    <span aria-hidden className="size-2.5 animate-spin rounded-full border-[1.5px] border-accent border-t-transparent" />
                    Preparando respuesta
                </span>
            );
        case 'ready':
            return <span className={`${base} bg-accent-soft text-accent`}>✓ Propuesta lista</span>;
        case 'resolved':
            return <span className={`${base} bg-ok/10 text-ok`}>Resuelta</span>;
        case 'dismissed':
            return <span className={`${base} bg-soft text-faint`}>Descartada</span>;
        case 'meeting':
            return <span className={`${base} bg-soft text-ink`}>Reunión</span>;
        default:
            return <span className={`${base} bg-soft text-muted`}>Sin importancia</span>;
    }
}

/** " · 3 mensajes" when the conversation brought more than one since it was last dealt with. */
function messageCount(alert: Alert): string {
    const count = alert.messages?.length ?? 0;

    return count > 1 ? ` · ${count} mensajes` : '';
}

function Row({ alert, onOpen, delay = 0 }: { alert: Alert; onOpen: (alert: Alert) => void; delay?: number }) {
    const addressed = alert.state === 'resolved' || alert.state === 'dismissed';
    const quiet = addressed || alert.state === 'routine';

    return (
        <li className="alerts-row-in" style={{ animationDelay: `${delay}ms` }}>
            <button
                type="button"
                onClick={() => onOpen(alert)}
                className={`grid w-full grid-cols-[auto_minmax(0,1fr)] gap-x-2.5 rounded-[10px] px-2.5 py-2 text-left transition-colors hover:bg-soft ${quiet ? 'opacity-70' : ''}`}
            >
                {addressed ? (
                    <span aria-label="Atendida" className="mt-px w-[8px] text-[11px] leading-none text-ok">
                        ✓
                    </span>
                ) : (
                    <i aria-hidden className={`mt-1.5 size-[8px] rounded-[3px] ${sourceDot[alert.source]}`} />
                )}
                <span className="min-w-0">
                    <span className="flex items-center gap-2 text-[11.5px] text-muted">
                        <span className="truncate">
                            {alert.actor_name ?? alert.source_label} · {alert.title}
                            {messageCount(alert)}
                        </span>
                        <time className="ml-auto flex-none font-mono">{timeFormat.format(new Date(alert.received_at))}</time>
                    </span>
                    <span className="mt-0.5 flex items-start gap-2">
                        <span className={`line-clamp-2 min-w-0 flex-1 text-[13px] ${alert.state === 'judging' ? 'text-faint italic' : ''}`}>
                            {alert.state === 'judging' ? 'Viendo si espera tu respuesta…' : alert.why}
                        </span>
                        <StateChip state={alert.state} />
                    </span>
                    {alert.proposal?.summary && alert.state === 'ready' && (
                        <span className="mt-0.5 line-clamp-1 block text-[12px] text-accent">→ {alert.proposal.summary}</span>
                    )}
                    {alert.task && alert.task.status !== 'cancelled' && (
                        <span className="mt-0.5 line-clamp-1 block text-[12px] text-ok">
                            ◷ {alert.task.status_label}
                            {alert.task.starts_at ? ` · ${taskTime.format(new Date(alert.task.starts_at))}` : ''}
                        </span>
                    )}
                </span>
            </button>
        </li>
    );
}

/** One incoming message, shown briefly and updated live while it is judged. */
function IncomingToast({
    alert,
    onOpen,
    onClose,
    onTellMe,
    listening,
}: {
    alert: Alert;
    onOpen: (alert: Alert) => void;
    onClose: () => void;
    onTellMe?: (alert: Alert) => void;
    listening: boolean;
}) {
    const close = useRef(onClose);
    close.current = onClose;

    // Each state has its own time on screen; a change of state starts it again.
    useEffect(() => {
        const timer = window.setTimeout(() => close.current(), TOAST_FOR[alert.state] ?? JUDGING_AT_MOST);

        return () => window.clearTimeout(timer);
    }, [alert.state]);

    const waiting = WAITING.has(alert.state);

    return (
        <div
            role="status"
            aria-live="polite"
            className={`alerts-toast-in overflow-hidden rounded-[14px] border bg-surface shadow-card ${waiting ? 'border-accent/40' : 'border-line'}`}
        >
            <button type="button" onClick={() => onOpen(alert)} className="grid w-full grid-cols-[auto_minmax(0,1fr)] gap-x-3 px-4 py-3 text-left hover:bg-soft">
                <i aria-hidden className={`mt-1.5 size-[9px] rounded-[3px] ${sourceDot[alert.source]}`} />
                <div className="min-w-0">
                    <div className="flex items-center justify-between gap-2 text-[12px] text-muted">
                        <span className="truncate">
                            {alert.source_label} · {alert.actor_name}
                        </span>
                        <StateChip state={alert.state} />
                    </div>
                    <b className="mt-0.5 block truncate text-[13.5px] font-semibold">
                        {alert.title}
                        <span className="font-normal text-muted">{messageCount(alert)}</span>
                    </b>
                    <p className={`mt-px line-clamp-2 text-[13px] ${alert.state === 'judging' ? 'text-faint italic' : 'text-muted'}`}>
                        {alert.state === 'judging' ? 'Viendo si espera tu respuesta…' : alert.why}
                    </p>
                </div>
            </button>

            {(alert.meeting_url || (waiting && onTellMe)) && (
                <div className="flex items-center justify-end gap-2 border-t border-line px-4 py-2 text-[12.5px]">
                    {alert.meeting_url && (
                        <a
                            href={alert.meeting_url}
                            target="_blank"
                            rel="noreferrer noopener"
                            onClick={onClose}
                            className="mr-auto rounded-full border border-line px-3 py-1 font-medium text-accent hover:bg-accent-soft"
                        >
                            Unirse
                        </a>
                    )}
                    {waiting && onTellMe &&
                        (listening ? (
                            <span className="text-muted">
                                Di <b className="font-medium text-ink">«dime»</b> y te lo cuento
                            </span>
                        ) : (
                            <button
                                type="button"
                                onClick={() => {
                                    onClose();
                                    onTellMe(alert);
                                }}
                                className="rounded-full bg-ink px-3 py-1 font-medium text-bg hover:opacity-90"
                            >
                                Dime
                            </button>
                        ))}
                </div>
            )}
        </div>
    );
}

type AlertCenterProps = {
    /** What came in lately when the page loaded, so it shows at once. */
    pending?: Alert[];
    /** Open the alert on screen (clicking it). */
    onOpen?: (alert: Alert) => void;
    /** Open the full history of alerts. */
    onHistory?: () => void;
    /** Something that matters came in: an alert waiting for an answer, or a meeting (e.g. to give the voice assistant context). */
    onArrive?: (alert: Alert) => void;
    /** The person wants the assistant to tell them about it. */
    onTellMe?: (alert: Alert) => void;
    /** Whether the assistant is listening, so saying "dime" is enough. */
    listening?: boolean;
};

/**
 * Everything that comes in from Gmail and Slack, as in docs/prototype.html:
 * each message shows at once and updates live while it is judged; only what
 * waits for the person's answer rings. The button keeps count of what is
 * still pending and opens the list with where each one stands.
 */
export default function AlertCenter({ pending: initial = [], onOpen, onHistory, onArrive, onTellMe, listening = false }: AlertCenterProps) {
    const feed = useAlertFeed({
        initial,
        onArrive: (alert) => alert.state === 'meeting' && onArrive?.(alert),
        onImportant: onArrive,
    });
    const [open, setOpen] = useState(false);

    const waiting = feed.items.filter((item) => WAITING.has(item.state));
    const working = feed.items.some((item) => item.state === 'judging' || item.state === 'planning');
    const recent = feed.items.filter((item) => !WAITING.has(item.state)).slice(0, 20);

    const openAlert = (alert: Alert) => {
        setOpen(false);
        feed.dismissFresh(alert.id);

        if (onOpen) {
            onOpen(alert);
        } else if (alert.external_url) {
            window.open(alert.external_url, '_blank', 'noopener');
        }
    };

    // Escape closes the list.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    return (
        <div className="fixed top-[60px] right-4 z-50 flex w-[min(380px,calc(100vw-32px))] flex-col items-end gap-2">
            <div className="flex items-start gap-2">
                {/* Wingman's bubble, beside the alerts, only while in a call. */}
                <WingmanBubble />

                <button
                    type="button"
                    aria-expanded={open}
                    aria-label={`Avisos: ${waiting.length} pendientes`}
                    onClick={() => setOpen(!open)}
                    className={`flex items-center gap-2 rounded-full border bg-surface px-3 py-1.5 text-[12.5px] shadow-card transition-all hover:bg-soft ${open ? 'border-accent/50' : 'border-line'}`}
                >
                    <span
                        title={feed.live ? 'Conectado en tiempo real' : 'Sin conexión en tiempo real: se actualiza cada poco'}
                        className={`relative size-[7px] rounded-full ${feed.live ? 'bg-ok text-ok' : 'bg-faint text-faint'} ${working ? 'alerts-ping' : ''}`}
                    />
                    {waiting.length === 0 ? 'Al día' : waiting.length === 1 ? '1 pendiente' : `${waiting.length} pendientes`}
                    {working && <span className="text-muted">· analizando</span>}
                </button>
            </div>

            {open ? (
                <div className="alerts-panel-in max-h-[70vh] w-full overflow-auto rounded-[16px] border border-line bg-surface p-1.5 shadow-card">
                    {waiting.length > 0 && (
                        <section aria-label="Pendientes de responder">
                            <h3 className="px-2.5 pt-2 pb-1 text-[11px] font-medium tracking-wide text-muted uppercase">Pendientes</h3>
                            <ol>
                                {waiting.map((item, index) => (
                                    <Row key={item.id} alert={item} onOpen={openAlert} delay={index * 35} />
                                ))}
                            </ol>
                        </section>
                    )}

                    <section aria-label="Entrantes">
                        <h3 className="px-2.5 pt-2 pb-1 text-[11px] font-medium tracking-wide text-muted uppercase">Entrantes</h3>
                        {recent.length === 0 ? (
                            <p className="px-2.5 pb-3 text-[13px] text-faint">Nada nuevo en las últimas 48 horas.</p>
                        ) : (
                            <ol>
                                {recent.map((item, index) => (
                                    <Row key={item.id} alert={item} onOpen={openAlert} delay={(waiting.length + index) * 35} />
                                ))}
                            </ol>
                        )}
                    </section>

                    {onHistory && (
                        <button
                            type="button"
                            onClick={() => {
                                setOpen(false);
                                onHistory();
                            }}
                            className="mt-1 w-full rounded-[10px] border-t border-line px-2.5 py-2.5 text-center text-[12.5px] text-accent hover:bg-soft"
                        >
                            Ver todo el historial
                        </button>
                    )}
                </div>
            ) : (
                feed.fresh.map((item) => (
                    <div key={item.id} className="w-full">
                        <IncomingToast
                            alert={item}
                            onOpen={openAlert}
                            onClose={() => feed.dismissFresh(item.id)}
                            onTellMe={onTellMe}
                            listening={listening}
                        />
                    </div>
                ))
            )}
        </div>
    );
}
