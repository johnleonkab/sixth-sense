import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { StateChip } from '@/alerts/alert-headline';
import { Button, ButtonLink, buttonClass } from '@/components/button';
import { ServiceIconFor } from '@/components/service-icon';

export type IntentRow = {
    key: string;
    what: string;
    who: string;
    deadline: string | null;
    last_change: string | null;
    source?: string | null;
    source_label?: string | null;
    source_url: string | null;
    activity_at?: string | null;
    opened_at?: string | null;
    urgent?: boolean;
    messages_count?: number;
    alert_id?: number | null;
    proposal_ready?: boolean;
    importance: 'alta' | 'media' | 'baja';
    uncertain: boolean;
    message: { source: string; external_id: string; text: string } | null;
};

export type IntentGroups = {
    waiting_on_me: IntentRow[];
    waiting_on_others: IntentRow[];
    quiet: IntentRow[];
};

const groups: { key: keyof IntentGroups; title: string }[] = [
    { key: 'waiting_on_me', title: 'Para mí' },
    { key: 'waiting_on_others', title: 'Espero algo de otros' },
    { key: 'quiet', title: 'Sin movimiento' },
];

const levels = [
    { key: 'alta', score: 70, label: 'Alta', dot: 'bg-accent' },
    { key: 'media', score: 40, label: 'Media', dot: 'bg-muted' },
    { key: 'baja', score: 10, label: 'Baja', dot: 'bg-faint' },
] as const;

const clockFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });
const weekdayClockFormat = new Intl.DateTimeFormat('es-ES', { weekday: 'short', hour: '2-digit', minute: '2-digit' });
const shortDateFormat = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });
const weekdayDateFormat = new Intl.DateTimeFormat('es-ES', { weekday: 'short', day: 'numeric', month: 'short' });
const fullTimeFormat = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' });

function startOfDay(date: Date): number {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

function formatActivity(iso: string | null | undefined, now: Date): { relative: string; exact: string; fresh: boolean; aging: boolean } | null {
    if (!iso) {
        return null;
    }

    const when = new Date(iso);

    if (Number.isNaN(when.getTime())) {
        return null;
    }

    const diffMs = Math.max(0, now.getTime() - when.getTime());
    const minutes = Math.floor(diffMs / 60000);
    const hours = Math.floor(minutes / 60);
    const dayDiff = Math.round((startOfDay(now) - startOfDay(when)) / 86400000);

    if (minutes < 1) {
        return { relative: 'ahora mismo', exact: clockFormat.format(when), fresh: true, aging: false };
    }

    if (minutes < 60) {
        return { relative: `hace ${minutes} min`, exact: clockFormat.format(when), fresh: minutes <= 30, aging: false };
    }

    if (dayDiff === 0) {
        return { relative: `hace ${hours} h`, exact: clockFormat.format(when), fresh: false, aging: false };
    }

    if (dayDiff === 1) {
        return { relative: 'ayer', exact: clockFormat.format(when), fresh: false, aging: false };
    }

    if (dayDiff < 7) {
        return {
            relative: `hace ${dayDiff} días`,
            exact: weekdayClockFormat.format(when).replaceAll('.', ''),
            fresh: false,
            aging: dayDiff >= 2,
        };
    }

    return {
        relative: shortDateFormat.format(when).replaceAll('.', ''),
        exact: clockFormat.format(when),
        fresh: false,
        aging: true,
    };
}

function formatDeadline(deadline: string | null, now: Date): { label: string; tone: 'overdue' | 'soon' | 'normal' } | null {
    if (!deadline) {
        return null;
    }

    const due = new Date(`${deadline}T12:00:00`);

    if (Number.isNaN(due.getTime())) {
        return null;
    }

    const dayDiff = Math.round((startOfDay(due) - startOfDay(now)) / 86400000);

    if (dayDiff < 0) {
        return { label: `Venció el ${shortDateFormat.format(due).replaceAll('.', '')}`, tone: 'overdue' };
    }

    if (dayDiff === 0) {
        return { label: 'Para hoy', tone: 'soon' };
    }

    if (dayDiff === 1) {
        return { label: 'Para mañana', tone: 'soon' };
    }

    return { label: `Para el ${weekdayDateFormat.format(due).replaceAll('.', '')}`, tone: 'normal' };
}

function correct(
    key: string,
    body: {
        action: string;
        importance?: number;
        target_key?: string;
        message_source?: string;
        message_external_id?: string;
        what?: string;
        messages?: { source?: string; external_id?: string }[];
    },
    onError?: () => void,
) {
    router.post(`/intents/${encodeURIComponent(key)}/corrections`, body, {
        preserveScroll: true,
        onError,
    });
}

function KebabIcon() {
    return (
        <svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true">
            <circle cx="8" cy="3.25" r="1.25" />
            <circle cx="8" cy="8" r="1.25" />
            <circle cx="8" cy="12.75" r="1.25" />
        </svg>
    );
}

function ExternalArrow() {
    return (
        <svg viewBox="0 0 14 14" width="12" height="12" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M4.2 9.8 9.8 4.2M5.2 4.2h4.6v4.6" />
        </svg>
    );
}

function Row({
    row,
    index,
    now,
    others,
    onOpenAlert,
    onClosed,
    onReopen,
}: {
    row: IntentRow;
    index: number;
    now: Date;
    others: { key: string; what: string }[];
    onOpenAlert?: (alertId: number) => void;
    onClosed: (key: string) => void;
    onReopen: (key: string) => void;
}) {
    const [target, setTarget] = useState(others[0]?.key ?? '');
    const [menuOpen, setMenuOpen] = useState(false);
    const [closing, setClosing] = useState(false);
    const [optimisticImportance, setOptimisticImportance] = useState<IntentRow['importance'] | null>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    const currentImportance = optimisticImportance ?? row.importance;
    const activity = formatActivity(row.activity_at, now);
    const due = formatDeadline(row.deadline, now);
    const alertId = row.alert_id ?? null;
    const canOpenHere = alertId !== null && Boolean(onOpenAlert);
    const messagesCount = row.messages_count ?? 0;
    const critical = Boolean(row.urgent) || due?.tone === 'overdue';
    const high = !critical && currentImportance === 'alta';

    useEffect(() => {
        setOptimisticImportance(null);
    }, [row.importance]);

    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!menuRef.current?.contains(event.target as Node)) {
                setMenuOpen(false);
            }
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        };

        window.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('keydown', onKeyDown);

        return () => {
            window.removeEventListener('pointerdown', onPointerDown);
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [menuOpen]);

    const changeImportance = (level: (typeof levels)[number]) => {
        setMenuOpen(false);

        if (level.key === currentImportance) {
            return;
        }

        setOptimisticImportance(level.key);
        correct(row.key, { action: 'importance', importance: level.score }, () => setOptimisticImportance(null));
    };

    const closeIntent = () => {
        setMenuOpen(false);
        setClosing(true);

        window.setTimeout(() => {
            onClosed(row.key);
            correct(row.key, { action: 'close' }, () => {
                setClosing(false);
                onReopen(row.key);
            });
        }, 230);
    };

    const tooltip = [
        row.opened_at ? `Abierto: ${fullTimeFormat.format(new Date(row.opened_at))}` : null,
        row.activity_at ? `Último mensaje: ${fullTimeFormat.format(new Date(row.activity_at))}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div
            className={`grid transition-[grid-template-rows,opacity,transform,margin] duration-250 ease-out ${
                menuOpen ? 'relative z-20' : ''
            } ${
                closing ? 'pointer-events-none mb-0 translate-x-3 scale-[0.985] grid-rows-[0fr] opacity-0' : 'mb-2.5 grid-rows-[1fr] opacity-100 last:mb-0'
            }`}
        >
            <div className={menuOpen ? 'overflow-visible' : 'overflow-hidden'}>
                <article
                    style={{ animationDelay: `${Math.min(index * 45, 360)}ms` }}
                    className={`intent-card-in group relative rounded-2xl border bg-surface px-4 py-3.5 transition-[border-color,background-color,box-shadow,transform] duration-200 hover:-translate-y-[1px] hover:shadow-card ${
                        menuOpen ? 'z-20' : ''
                    } ${
                        critical ? 'border-crit/35' : high ? 'border-accent/35' : 'border-line hover:border-faint/60'
                    }`}
                >
                    {(critical || high) && (
                        <span
                            aria-hidden="true"
                            className={`absolute top-3.5 bottom-3.5 left-0 w-[3px] rounded-r-full ${critical ? 'bg-crit' : 'bg-accent'}`}
                        />
                    )}

                    <div className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2.5">
                        {row.source ? (
                            <ServiceIconFor reference={row.source} size={16} />
                        ) : (
                            <span aria-hidden="true" className="size-2 rounded-full bg-faint" />
                        )}

                        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-muted">
                            <span className="truncate font-medium text-ink">{row.who || 'Alguien'}</span>
                            {row.source_label && <span className="truncate text-muted">· {row.source_label}</span>}
                            {row.proposal_ready && <StateChip state="ready" />}
                            {!row.proposal_ready && row.urgent && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-crit-soft px-2 py-[2px] text-[10.5px] font-medium text-crit">
                                    Urgente
                                </span>
                            )}
                            {!row.proposal_ready && !row.urgent && currentImportance === 'alta' && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 py-[2px] text-[10.5px] font-medium text-accent">
                                    Alta
                                </span>
                            )}
                        </div>

                        <div className="flex flex-none items-center gap-1">
                            {activity && (
                                <time
                                    dateTime={row.activity_at ?? undefined}
                                    title={tooltip || undefined}
                                    className="inline-flex items-center gap-1.5 rounded-full bg-soft/75 px-2.5 py-0.5 text-[11.5px] whitespace-nowrap"
                                >
                                    {(critical || activity.fresh || activity.aging) && (
                                        <span
                                            aria-hidden="true"
                                            className={`size-1.5 rounded-full ${
                                                critical ? 'intent-live-dot bg-crit' : activity.aging ? 'bg-warn' : 'intent-live-dot bg-accent'
                                            }`}
                                        />
                                    )}
                                    <span className={activity.aging ? 'font-medium text-warn' : 'text-ink/85'}>{activity.relative}</span>
                                    <span className="hidden font-mono text-[11px] text-muted tabular-nums sm:inline">· {activity.exact}</span>
                                </time>
                            )}

                            <div ref={menuRef} className="relative">
                                <button
                                    type="button"
                                    aria-label={`Opciones para «${row.what}»`}
                                    aria-haspopup="menu"
                                    aria-expanded={menuOpen}
                                    onClick={() => setMenuOpen((open) => !open)}
                                    className={`grid size-7 place-items-center rounded-full text-muted transition-colors hover:bg-soft hover:text-ink ${
                                        menuOpen ? 'bg-soft text-ink' : 'md:opacity-65 md:group-focus-within:opacity-100 md:group-hover:opacity-100'
                                    }`}
                                >
                                    <KebabIcon />
                                </button>

                                {menuOpen && (
                                    <div
                                        role="menu"
                                        aria-label="Opciones del pendiente"
                                        className="alerts-panel-in absolute top-8 right-0 z-30 min-w-[172px] rounded-xl border border-line bg-surface p-1 shadow-card"
                                    >
                                        <p className="px-2.5 pt-1.5 pb-1 text-[11px] font-medium text-muted">Prioridad</p>
                                        {levels.map((level) => {
                                            const active = currentImportance === level.key;

                                            return (
                                                <button
                                                    key={level.key}
                                                    type="button"
                                                    role="menuitemradio"
                                                    aria-checked={active}
                                                    onClick={() => changeImportance(level)}
                                                    className="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[13px] text-ink transition-colors hover:bg-soft"
                                                >
                                                    <span aria-hidden="true" className={`size-1.5 rounded-full ${level.dot}`} />
                                                    <span className="flex-1">{level.label}</span>
                                                    {active && <span className="font-mono text-[11.5px] text-accent">✓</span>}
                                                </button>
                                            );
                                        })}
                                        <div role="separator" className="my-1 border-t border-line" />
                                        <button
                                            type="button"
                                            role="menuitem"
                                            onClick={closeIntent}
                                            className="flex w-full items-center rounded-lg px-2.5 py-1.5 text-left text-[13px] text-crit transition-colors hover:bg-crit-soft"
                                        >
                                            Cerrar
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="mt-1.5 pl-[26px]">
                        <h3 className={`text-[15px] leading-[1.38] font-medium tracking-[-0.01em] ${currentImportance === 'baja' ? 'text-ink/85' : 'text-ink'}`}>
                            {row.what}
                            {messagesCount > 1 && <span className="text-[13px] font-normal text-muted"> · {messagesCount} mensajes</span>}
                        </h3>

                        {row.last_change && row.last_change !== row.what && (
                            <p className="mt-0.5 line-clamp-2 text-[13.5px] leading-[1.45] text-muted">{row.last_change}</p>
                        )}

                        {row.uncertain && (
                            <div className="mt-3 rounded-xl bg-soft px-3 py-2.5">
                                <p className="text-[13px]">¿Es esto?</p>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {others.length > 0 && row.message && (
                                        <>
                                            <select
                                                value={target}
                                                onChange={(event) => setTarget(event.target.value)}
                                                aria-label={`Mover «${row.what}»`}
                                                className="max-w-full rounded-full border border-line bg-surface px-3 py-1 text-[13px]"
                                            >
                                                {others.map((other) => (
                                                    <option key={other.key} value={other.key}>
                                                        {other.what}
                                                    </option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                className={buttonClass({ variant: 'secondary', size: 'small' })}
                                                onClick={() =>
                                                    correct(row.key, {
                                                        action: 'rematch',
                                                        target_key: target,
                                                        message_source: row.message?.source,
                                                        message_external_id: row.message?.external_id,
                                                    })
                                                }
                                            >
                                                Mover
                                            </button>
                                        </>
                                    )}
                                    {row.message && (
                                        <button
                                            type="button"
                                            className={buttonClass({ variant: 'secondary', size: 'small' })}
                                            onClick={() =>
                                                correct(row.key, {
                                                    action: 'split',
                                                    what: row.message?.text,
                                                    messages: [{ source: row.message?.source, external_id: row.message?.external_id }],
                                                })
                                            }
                                        >
                                            Separar
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}

                        {(due || canOpenHere || row.source_url) && (
                            <div className="mt-2.5 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    {due && (
                                        <span
                                            className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11.5px] font-medium ${
                                                due.tone === 'overdue'
                                                    ? 'bg-crit-soft text-crit'
                                                    : due.tone === 'soon'
                                                      ? 'bg-warn-soft text-warn'
                                                      : 'bg-soft text-muted'
                                            }`}
                                        >
                                            <span aria-hidden="true">◷</span>
                                            {due.label}
                                        </span>
                                    )}
                                </div>

                                <div className="ml-auto flex flex-wrap items-center gap-2">
                                    {canOpenHere && row.source_url && (
                                        <ButtonLink
                                            href={row.source_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            variant="tertiary"
                                            size="small"
                                            iconRight={<ExternalArrow />}
                                        >
                                            {row.source_label ? `Abrir en ${row.source_label}` : 'Abrir origen'}
                                        </ButtonLink>
                                    )}

                                    {canOpenHere && alertId !== null && (
                                        <Button variant="primary" size="small" onClick={() => onOpenAlert?.(alertId)}>
                                            {row.proposal_ready ? 'Revisar propuesta' : 'Abrir aquí'}
                                        </Button>
                                    )}

                                    {!canOpenHere && row.source_url && (
                                        <ButtonLink
                                            href={row.source_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            variant="secondary"
                                            size="small"
                                            iconRight={<ExternalArrow />}
                                        >
                                            {row.source_label ? `Abrir en ${row.source_label}` : 'Ir al mensaje'}
                                        </ButtonLink>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </article>
            </div>
        </div>
    );
}

export default function IntentBoard({
    intents,
    only,
    onOpenAlert,
}: {
    intents: IntentGroups;
    only?: keyof IntentGroups;
    onOpenAlert?: (alertId: number) => void;
}) {
    const [now, setNow] = useState(() => new Date());
    const [hiddenKeys, setHiddenKeys] = useState<Set<string>>(() => new Set());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 30000);

        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        const present = new Set([...intents.waiting_on_me, ...intents.waiting_on_others, ...intents.quiet].map((row) => row.key));

        setHiddenKeys((previous) => {
            if (previous.size === 0) {
                return previous;
            }

            const next = new Set([...previous].filter((key) => present.has(key)));

            return next.size === previous.size ? previous : next;
        });
    }, [intents]);

    const shown = only ? groups.filter((group) => group.key === only) : groups;
    const all = shown.flatMap((group) => intents[group.key]).filter((row) => !hiddenKeys.has(row.key));

    const markClosed = (key: string) => {
        setHiddenKeys((previous) => new Set(previous).add(key));
    };

    const restoreClosed = (key: string) => {
        setHiddenKeys((previous) => {
            const next = new Set(previous);
            next.delete(key);

            return next;
        });
    };

    return (
        <div className="mx-auto w-full max-w-[760px] pt-1 pb-8">
            {shown.map((group) => {
                const items = intents[group.key].filter((row) => !hiddenKeys.has(row.key));

                return (
                    <section key={group.key} aria-labelledby={`intents-${group.key}`} className="mb-8">
                        <header className="intent-head-in flex items-baseline justify-between gap-3 px-1">
                            <h2 id={`intents-${group.key}`} className="text-[15px] font-medium tracking-[-0.01em] text-ink">
                                {group.title}
                            </h2>
                            <span className="font-mono text-[12px] text-muted tabular-nums">
                                {items.length === 0 ? 'Al día' : items.length === 1 ? '1 pendiente' : `${items.length} pendientes`}
                            </span>
                        </header>

                        {items.length === 0 ? (
                            <div className="intent-card-in mt-3 rounded-2xl border border-line bg-surface/60 px-5 py-8 text-center">
                                <p className="text-[14px] text-muted">Nada esperando tu respuesta.</p>
                            </div>
                        ) : (
                            <div className="mt-3">
                                {items.map((row, index) => (
                                    <Row
                                        key={row.key}
                                        row={row}
                                        index={index}
                                        now={now}
                                        others={all.filter((other) => other.key !== row.key).map((other) => ({ key: other.key, what: other.what }))}
                                        onOpenAlert={onOpenAlert}
                                        onClosed={markClosed}
                                        onReopen={restoreClosed}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                );
            })}
        </div>
    );
}
