import { useState } from 'react';

import { sourceDot, StateChip } from '@/alerts/alert-center';
import type { Alert } from '@/alerts/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { getJson } from '@/lib/http';

type Filter = 'all' | 'pending' | 'addressed' | 'routine';

type HistoryProps = { filter: Filter; items: Alert[]; next: string | null };

const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'Todos' },
    { key: 'pending', label: 'Pendientes' },
    { key: 'addressed', label: 'Atendidos' },
    { key: 'routine', label: 'Sin importancia' },
];

const dayFormat = new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });
const timeFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

const resolutionText: Record<string, string> = {
    replied: 'Respondiste tú',
    marked_done: 'Marcada como resuelta',
    dismissed: 'Descartada',
};

function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date(today.getTime() - 86_400_000);

    if (date.toDateString() === today.toDateString()) {
        return 'Hoy';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Ayer';
    }

    const text = dayFormat.format(date);

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * Every alert that came in, by day, newest first. What was already
 * addressed stays, clearly marked: dimmed, ticked, and how and when.
 */
const AlertsHistory: CanvasComponent<HistoryProps> = ({ index, ...props }) => {
    const { runTool } = useCanvas();
    const [filter, setFilter] = useState<Filter>(props.filter);
    const [items, setItems] = useState<Alert[]>(props.items);
    const [next, setNext] = useState<string | null>(props.next);
    const [loading, setLoading] = useState(false);

    const load = async (wanted: Filter, before: string | null) => {
        setLoading(true);

        try {
            const query = new URLSearchParams({ filter: wanted, ...(before ? { before } : {}) });
            const page = await getJson<{ items: Alert[]; next: string | null }>(`/alerts/history?${query}`);
            setItems((current) => (before ? [...current, ...page.items] : page.items));
            setNext(page.next);
        } finally {
            setLoading(false);
        }
    };

    const days = items.reduce<{ day: string; items: Alert[] }[]>((groups, item) => {
        const day = dayLabel(item.received_at);
        const group = groups.at(-1);

        if (group?.day === day) {
            group.items.push(item);
        } else {
            groups.push({ day, items: [item] });
        }

        return groups;
    }, []);

    return (
        <CanvasFrame index={index} title="Historial de avisos" source="Avisos">
            <div role="tablist" aria-label="Filtrar" className="mb-4 flex flex-wrap gap-1.5">
                {filters.map((option) => (
                    <button
                        key={option.key}
                        type="button"
                        role="tab"
                        aria-selected={filter === option.key}
                        onClick={() => {
                            setFilter(option.key);
                            void load(option.key, null);
                        }}
                        className={`rounded-full px-3 py-1 text-[12.5px] transition-colors ${filter === option.key ? 'bg-ink text-bg' : 'border border-line hover:bg-soft'}`}
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            {items.length === 0 && !loading && <p className="py-8 text-center text-[13px] text-faint">No hay avisos aquí.</p>}

            <div className={`space-y-5 transition-opacity ${loading && !next ? 'opacity-50' : ''}`}>
                {days.map((group) => (
                    <section key={group.day} aria-label={group.day}>
                        <h4 className="mb-1.5 text-[12px] font-medium tracking-wide text-muted uppercase">{group.day}</h4>
                        <ol className="-mx-2.5">
                            {group.items.map((item) => {
                                const addressed = item.state === 'resolved' || item.state === 'dismissed';
                                const quiet = addressed || item.state === 'routine';

                                return (
                                    <li key={item.id} className="alerts-row-in border-t border-line first:border-t-0">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                item.source === 'calendar'
                                                    ? runTool('show_calendar', { start_date: (item.starts_at ?? item.received_at).slice(0, 10) })
                                                    : runTool('review_alert', { alert_id: item.id })
                                            }
                                            className={`grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 rounded-[10px] px-2.5 py-2.5 text-left hover:bg-soft ${quiet ? 'opacity-60 hover:opacity-100' : ''}`}
                                        >
                                            <span className="mt-1 flex w-4 justify-center">
                                                {addressed ? <span className="text-[13px] text-ok">✓</span> : <i aria-hidden className={`size-[8px] rounded-[3px] ${sourceDot[item.source]}`} />}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="flex items-baseline gap-2 text-[12px] text-muted">
                                                    <span className="truncate">
                                                        {item.source_label} · <b className="font-medium text-ink">{item.actor_name}</b> · {item.title}
                                                    </span>
                                                </span>
                                                <span className={`mt-0.5 block text-[13.5px] ${addressed ? 'line-through decoration-faint' : ''}`}>{item.why || '—'}</span>
                                                {addressed && (
                                                    <span className="mt-0.5 block text-[12px] text-ok">
                                                        {resolutionText[item.resolution ?? ''] ?? 'Atendida'}
                                                        {item.resolved_at ? ` · ${timeFormat.format(new Date(item.resolved_at))}` : ''}
                                                    </span>
                                                )}
                                                {item.task && item.task.status !== 'cancelled' && (
                                                    <span className="mt-0.5 block text-[12px] text-muted">◷ {item.task.status_label}: {item.task.title}</span>
                                                )}
                                            </span>
                                            <span className="flex flex-col items-end gap-1">
                                                <StateChip state={item.state} />
                                                <time className="font-mono text-[11px] text-faint">{timeFormat.format(new Date(item.received_at))}</time>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                ))}
            </div>

            {next && (
                <div className="mt-4 flex justify-center">
                    <button type="button" disabled={loading} onClick={() => void load(filter, next)} className="rounded-full border border-line px-4 py-1.5 text-[12.5px] hover:bg-soft disabled:opacity-50">
                        {loading ? 'Cargando…' : 'Cargar más'}
                    </button>
                </div>
            )}
        </CanvasFrame>
    );
};

export default AlertsHistory;
