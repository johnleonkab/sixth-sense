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
    // A confirmed change is acknowledged with a sound once the service really did it.
    const actions = useActions({ onExecuted: (action) => ring(['mail.send', 'chat.send'].includes(action.type) ? 'sent' : 'done') });

    // For now each canvas replaces what was on screen; layouts with two come with the agent protocol.
    const show = (directive: CanvasDirective) => {
        if (directive.props.action) {
            actions.track(directive.props.action as ActionState);
        }

        setCanvases([{ id: nextCanvasId.current++, directive }]);
    };

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

            <div className="grid h-full grid-rows-[auto_minmax(0,1fr)_auto] px-4">
                <header className="mx-auto grid w-full max-w-[1120px] grid-cols-[1fr_auto_1fr] items-center gap-3 pt-4 pb-1.5 text-[13px] text-muted">
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
                        <Link href="/settings" className="rounded-lg px-2.5 py-1 hover:bg-soft hover:text-ink">
                            Configuración
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded-lg px-2.5 py-1 hover:bg-soft hover:text-ink"
                        >
                            Salir
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
                                style={{ zoom: auth.user?.display_scale ?? 1 }}
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

                <VoiceDock voice={voice} />

                <AlertCenter
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
            </div>
        </>
    );
}
