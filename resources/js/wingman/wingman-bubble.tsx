import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { StatusChip } from '@/canvas/jira/jira-bits';
import InsightCard from '@/wingman/insight-card';
import { useLiveCall } from '@/wingman/use-live-call';

const plural = (count: number, one: string, many: string): string => `${count} ${count === 1 ? one : many}`;

function useElapsed(since: string | null): string {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 15_000);

        return () => window.clearInterval(timer);
    }, []);

    if (!since) {
        return '';
    }

    const minutes = Math.max(0, Math.floor((now - new Date(since).getTime()) / 60_000));

    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/**
 * Wingman's bubble: only while the person is in a call. It says whether
 * Wingman is listening and, opened, shows the call: what was said last and
 * the context at hand, with a way to pause it or open it whole.
 */
export default function WingmanBubble() {
    const { call, setPaused } = useLiveCall();
    const [open, setOpen] = useState(false);
    const elapsed = useElapsed(call?.joined_at ?? null);
    const [dismissed, setDismissed] = useState<number[]>([]);
    const insights = (call?.insights ?? []).filter((insight) => !dismissed.includes(insight.id));

    // A new card is said by the bubble itself for a few seconds, in silence.
    const newest = insights[0] ?? null;
    const [seen, setSeen] = useState<number | null>(null);
    const [announced, setAnnounced] = useState<string | null>(null);

    useEffect(() => {
        if (!newest || newest.id === seen) {
            return;
        }

        // What was there when the page opened is not news.
        if (seen !== null && !open) {
            setAnnounced(newest.title);
        }

        setSeen(newest.id);
    }, [newest, seen, open]);

    useEffect(() => {
        if (!announced) {
            return;
        }

        const timer = window.setTimeout(() => setAnnounced(null), 10_000);

        return () => window.clearTimeout(timer);
    }, [announced]);

    // Escape closes it.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    if (!call) {
        return null;
    }

    return (
        <div className="relative">
            <button
                type="button"
                aria-expanded={open}
                aria-label={`Wingman: ${call.paused ? 'en pausa' : 'escuchando'} ${call.title}`}
                onClick={() => {
                    setOpen(!open);
                    setAnnounced(null);
                }}
                className={`alerts-row-in flex items-center gap-2 rounded-full border bg-surface px-3 py-1.5 text-[12.5px] shadow-card transition-all hover:bg-soft ${open ? 'border-accent/50' : 'border-accent/30'}`}
            >
                <span className={`relative size-[7px] rounded-full ${call.paused ? 'bg-faint text-faint' : 'alerts-ping bg-accent text-accent'}`} />
                Wingman
                {announced ? (
                    <span className="max-w-[260px] truncate font-medium text-accent">· {announced}</span>
                ) : (
                    <span className="text-muted">· {call.paused ? (call.paused_reason ? `en pausa (${call.paused_reason})` : 'en pausa') : 'escuchando'}</span>
                )}
            </button>

            {open && (
                <div className="alerts-panel-in absolute top-full right-0 z-10 mt-2 max-h-[70vh] w-[min(380px,calc(100vw-32px))] overflow-auto rounded-[16px] border border-line bg-surface p-3.5 shadow-card">
                    <p className="text-[11px] font-medium tracking-wide text-muted uppercase">
                        {call.paused ? (call.paused_reason ? `En pausa por tu ${call.paused_reason}` : 'En pausa') : 'Escuchando'} · {elapsed}
                    </p>
                    <h3 className="mt-0.5 text-[15px] font-medium tracking-[-0.01em]">{call.title}</h3>
                    {call.attendees.length > 0 && <p className="truncate text-[12.5px] text-muted">Con {call.attendees.join(', ')}</p>}

                    {insights.length > 0 && (
                        <section aria-label="Lo que Wingman te aporta" className="mt-3 space-y-2">
                            {insights.map((insight) => (
                                <InsightCard key={insight.id} insight={insight} compact onDismiss={(id) => setDismissed((list) => [...list, id])} />
                            ))}
                        </section>
                    )}

                    {call.summary && <p className="mt-2.5 text-[13px]">{call.summary}</p>}
                    {call.open_questions.length > 0 && (
                        <ul className="mt-1.5 space-y-0.5 text-[12.5px] text-warn">
                            {call.open_questions.map((question) => (
                                <li key={question}>? {question}</li>
                            ))}
                        </ul>
                    )}

                    {(call.issues.length > 0 || call.pages.length > 0) && (
                        <ul className="mt-3 space-y-1 text-[13px]">
                            {call.issues.map((issue) => (
                                <li key={issue.ref} className="flex items-baseline gap-2">
                                    <a href={issue.url ?? undefined} target="_blank" rel="noreferrer noopener" className="font-mono text-[12px] text-jira hover:underline">
                                        {issue.key}
                                    </a>
                                    <span className="min-w-0 truncate">{issue.summary}</span>
                                    <StatusChip issue={issue} />
                                </li>
                            ))}
                            {call.pages.map((page) => (
                                <li key={page.ref} className="truncate">
                                    <a href={page.url ?? undefined} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                                        {page.title}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}

                    <section aria-label="Lo último que se ha dicho" className="mt-3 rounded-xl bg-soft px-3 py-2.5">
                        {call.lines.length === 0 ? (
                            <p className="text-[12.5px] text-faint">{call.paused ? 'No se está guardando lo que se dice.' : 'Todavía no se ha dicho nada.'}</p>
                        ) : (
                            <ol className="space-y-1 text-[12.5px]">
                                {call.lines.map((line) => (
                                    <li key={line.id} className={line.final ? '' : 'text-muted'}>
                                        <b className={`font-medium ${line.is_me ? 'text-accent' : ''}`}>{line.speaker}:</b> {line.text}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>

                    <div className="mt-3 flex items-center justify-between gap-2 text-[12.5px]">
                        <Link href="/wingman" onClick={() => setOpen(false)} className="text-faint hover:text-ink hover:underline">
                            Reuniones
                        </Link>
                        <span className="ml-auto text-faint">{[call.note_count > 0 && plural(call.note_count, 'nota', 'notas'), plural(call.segment_count, 'frase', 'frases')].filter(Boolean).join(' · ')}</span>
                        <div className="flex gap-1.5">
                            <button
                                type="button"
                                onClick={() => void setPaused(!call.paused)}
                                className="rounded-full border border-line px-3 py-1 text-muted hover:border-accent hover:text-accent"
                            >
                                {call.paused ? (call.paused_reason ? 'Escuchar esta vez' : 'Volver a escuchar') : 'Pausar'}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setOpen(false);
                                    router.visit(`/wingman/${call.id}`);
                                }}
                                className="rounded-full bg-accent px-3 py-1 font-medium text-accent-ink"
                            >
                                Abrir
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
