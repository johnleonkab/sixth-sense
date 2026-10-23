import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { answerIn } from '@/actions/answers';
import AlertCenter from '@/alerts/alert-center';
import type { Alert } from '@/alerts/types';
import { alertArrivedNote, tellMeNote } from '@/alerts/notes';
import { ring } from '@/alerts/sounds';
import type { ActionState } from '@/actions/types';
import { useActions } from '@/actions/use-actions';
import { CanvasContext } from '@/canvas/canvas-context';
import { describeCanvases } from '@/canvas/describe';
import { Canvas } from '@/canvas/registry';
import type { CanvasDirective } from '@/canvas/types';
import ListenToggle from '@/components/voice/listen-toggle';
import VoiceDock from '@/components/voice/voice-dock';
import Wordmark from '@/components/wordmark';
import { runTool } from '@/voice/run-tool';
import { useVoiceSession } from '@/voice/use-voice-session';

const dateFormat = new Intl.DateTimeFormat('es-ES', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const timeFormat = new Intl.DateTimeFormat('es-ES', {
    hour: '2-digit',
    minute: '2-digit',
});

/** What the screen says while a tool it asked for is on its way. */
const OPENING_LABELS: Record<string, string> = {
    review_alert: 'Abriendo la alerta…',
    show_alert_history: 'Abriendo el historial…',
    show_calendar: 'Abriendo el calendario…',
};

function Opening({ label }: { label: string }) {
    return (
        <div role="status" className="pointer-events-none sticky top-0 z-10 mx-auto mb-3 flex w-fit items-center gap-2 rounded-full border border-line bg-surface px-3.5 py-1.5 text-[13px] text-muted shadow-card">
            <i aria-hidden className="size-[7px] animate-pulse rounded-full bg-accent" />
            {label}
        </div>
    );
}

function useMinWidth(width: number): boolean {
    const [matches, setMatches] = useState(() => window.matchMedia(`(min-width: ${width}px)`).matches);

    useEffect(() => {
        const query = window.matchMedia(`(min-width: ${width}px)`);
        const onChange = () => setMatches(query.matches);
        query.addEventListener('change', onChange);

        return () => query.removeEventListener('change', onChange);
    }, [width]);

    return matches;
}

function SettingsIcon() {
    return (
        <svg viewBox="0 0 24 24" className="size-5 md:hidden" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden>
            <circle cx="12" cy="12" r="3" />
            <path d="M12 3.5v2.2M12 18.3v2.2M3.5 12h2.2M18.3 12h2.2M6 6l1.6 1.6M16.4 16.4 18 18M18 6l-1.6 1.6M7.6 16.4 6 18" strokeLinecap="round" />
        </svg>
    );
}

function LeaveIcon() {
    return (
        <svg viewBox="0 0 24 24" className="size-5 md:hidden" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden>
            <path d="M9 6H6.5A1.5 1.5 0 0 0 5 7.5v9A1.5 1.5 0 0 0 6.5 18H9" strokeLinecap="round" />
            <path d="M10 12h9M15 8l4 4-4 4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 15_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

export default function Home({ alerts }: { alerts: Alert[] }) {
    const { auth } = usePage().props;
    const [canvases, setCanvases] = useState<{ id: number; directive: CanvasDirective }[]>([]);
    const nextCanvasId = useRef(1);
    const canvasInHistory = useRef(false);
    const wide = useMinWidth(768);
    // A confirmed change is acknowledged with a sound once the service really did it.
    const actions = useActions({ onExecuted: (action) => ring(['mail.send', 'chat.send'].includes(action.type) ? 'sent' : 'done') });

    // For now each canvas replaces what was on screen; layouts with two come with the agent protocol.
    const [agent, setAgent] = useState<string | null>(null);

    const show = (directive: CanvasDirective) => {
        if (directive.view === 'sense.agent') {
            const name = directive.props.name;

            setAgent(typeof name === 'string' && name !== '' ? name : null);

            return;
        }

        if (directive.props.action) {
            actions.track(directive.props.action as ActionState);
        }

        setCanvases([{ id: nextCanvasId.current++, directive }]);

        if (window.matchMedia('(max-width: 767px)').matches && !canvasInHistory.current) {
            history.pushState({ sixthSenseCanvas: true }, '');
            canvasInHistory.current = true;
        }
    };

    useEffect(() => {
        const onPop = () => {
            if (!canvasInHistory.current) {
                return;
            }

            canvasInHistory.current = false;
            setCanvases([]);
        };

        window.addEventListener('popstate', onPop);

        return () => window.removeEventListener('popstate', onPop);
    }, []);

    /** "sí", "cancela" or "deshaz" answer the change on screen; only the person can. */
    const answer = async (text: string): Promise<void> => {
        const reply = answerIn(text);

        if (reply === 'undo') {
            const action = actions.undoable();

            if (action) {
                await actions.undo(action.id);
            }

            return;
        }

        const action = reply ? actions.awaitingAnswer() : null;

        if (action) {
            await (reply === 'yes' ? actions.confirm(action.id) : actions.cancel(action.id));
        }
    };

    // A conversation that starts, or picks up after dropping, knows what is on screen.
    const onScreen = useRef(canvases);
    onScreen.current = canvases;
    const voice = useVoiceSession({
        onCanvas: show,
        onUtterance: answer,
        screen: () => describeCanvases(onScreen.current.map((canvas) => canvas.directive)),
    });

    useEffect(() => {
        if (!voice.active) {
            setAgent(null);
        }
    }, [voice.active]);

    // One thing opens at a time from the screen: further clicks wait for it, and it shows.
    const [opening, setOpening] = useState<string | null>(null);
    const openingNow = useRef(false);

    const runFromScreen = (name: string, args: Record<string, unknown>) => {
        if (openingNow.current) {
            return;
        }

        openingNow.current = true;
        setOpening(OPENING_LABELS[name] ?? 'Abriendo…');

        void runTool({ id: `screen-${Date.now()}`, name, args })
            .then((outcome) => outcome.canvas && show(outcome.canvas))
            .finally(() => {
                openingNow.current = false;
                setOpening(null);
            });
    };
    const now = useNow();

    return (
        <>
            <Head title="Inicio" />

            <div className="grid h-full grid-rows-[auto_minmax(0,1fr)_auto] pr-[max(1rem,env(safe-area-inset-right))] pl-[max(1rem,env(safe-area-inset-left))]">
                <header className="chrome mx-auto grid w-full max-w-[1120px] grid-cols-[1fr_auto_1fr] items-center gap-3 pt-[max(0.75rem,env(safe-area-inset-top))] pb-1.5 text-[13px] text-muted">
                    <div className="flex min-w-0 items-center gap-3">
                        <Wordmark />
                        <span className="hidden sm:inline">
                            {dateFormat.format(now).replaceAll('.', '')} ·{' '}
                            <span className="font-mono tabular-nums">{timeFormat.format(now)}</span>
                        </span>
                    </div>

                    <ListenToggle voice={voice} />

                    <div className="flex items-center justify-end gap-1">
                        <span className="hidden px-2.5 sm:inline">{auth.user?.name}</span>
                        <AlertCenter
                            embedInHeader
                            pending={alerts}
                            onHistory={() => runFromScreen('show_alert_history', {})}
                            listening={voice.active}
                            onOpen={(alert) =>
                                alert.source === 'calendar'
                                    ? runFromScreen('show_calendar', { start_date: (alert.starts_at ?? alert.received_at).slice(0, 10) })
                                    : runFromScreen('review_alert', { alert_id: alert.id })
                            }
                            onArrive={(alert) => void voice.tell(alertArrivedNote(alert), false)}
                            onTellMe={(alert) => void voice.tell(tellMeNote(alert), true)}
                        />
                        <Link href="/settings" aria-label="Configuración" className="grid size-11 place-items-center rounded-lg hover:bg-soft hover:text-ink md:size-auto md:px-2.5 md:py-1">
                            <SettingsIcon />
                            <span className="max-md:sr-only">Configuración</span>
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            aria-label="Salir"
                            className="grid size-11 place-items-center rounded-lg hover:bg-soft hover:text-ink md:size-auto md:px-2.5 md:py-1"
                        >
                            <LeaveIcon />
                            <span className="max-md:sr-only">Salir</span>
                        </Link>
                    </div>
                </header>

                <main aria-live="polite" aria-busy={opening !== null} className="min-h-0 overflow-auto py-4">
                    {opening && <Opening label={opening} />}
                    {canvases.length === 0 ? (
                        !opening && (
                            <p className="grid h-full place-items-center text-[15px] text-faint">
                                {voice.active ? '' : 'Aquí aparecerá lo que necesites ver.'}
                            </p>
                        )
                    ) : (
                        <CanvasContext value={{ runTool: runFromScreen, actions }}>
                            <div
                                // The chosen display size scales the canvases, not the voice zone.
                                style={wide ? { zoom: auth.user?.display_scale ?? 1 } : undefined}
                                className={`mx-auto grid items-start gap-5 transition-opacity ${opening ? 'pointer-events-none opacity-50' : ''} ${
                                    canvases.length > 1
                                        ? 'max-w-[1120px] md:grid-cols-2'
                                        : canvases[0].directive.layout === 'wide'
                                          ? 'max-w-[1120px]'
                                          : 'max-w-[760px]'
                                }`}
                            >
                                {canvases.map((canvas, index) => (
                                    <Canvas key={canvas.id} directive={canvas.directive} index={index + 1} />
                                ))}
                            </div>
                        </CanvasContext>
                    )}
                </main>

                <VoiceDock voice={voice} agent={agent} />
            </div>
        </>
    );
}
