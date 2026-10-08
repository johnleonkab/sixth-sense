import { useEffect, useState } from 'react';

import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';

function useSecondsLeft(until: string | null): number {
    const read = () => (until ? Math.max(0, Math.ceil((Date.parse(until) - Date.now()) / 1000)) : 0);
    const [seconds, setSeconds] = useState(read);

    useEffect(() => {
        setSeconds(read());
        const timer = window.setInterval(() => setSeconds(read()), 250);

        return () => window.clearInterval(timer);
    }, [until]);

    return seconds;
}

/**
 * The person's controls for a proposed change: confirm or cancel, then a short
 * window to undo, then the outcome. Voice works too ("sí", "cancela", "deshaz").
 */
type ActionBarProps = {
    initial: ActionState;
    doneLabel: string;
    /** Label of the link to see the result, e.g. "Abrir en Docs". */
    linkLabel?: string;
    /** Name of what is applied, for the confirm button ("aplicar", "enviar"). */
    verb?: string;
    /** "Se aplicará", "Se enviará"… before the countdown. */
    countdownLabel?: string;
};

export default function ActionBar({ initial, doneLabel, linkLabel = 'Abrir en Docs', verb = 'aplicar', countdownLabel }: ActionBarProps) {
    const { actions } = useCanvas();
    const action = actions.actions[initial.id] ?? initial;
    const secondsLeft = useSecondsLeft(action.status === 'confirmed' ? action.undo_until : null);
    const url = typeof action.result?.url === 'string' ? action.result.url : null;

    useEffect(() => {
        if (!actions.actions[initial.id]) {
            actions.track(initial);
        }
    }, [initial.id]);

    const base = 'mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-4 text-[13px]';

    switch (action.status) {
        case 'pending':
            return (
                <div className={base}>
                    <button
                        type="button"
                        onClick={() => void actions.confirm(action.id)}
                        className="rounded-full bg-ink px-4 py-1.5 font-medium text-bg hover:opacity-90"
                    >
                        Sí, {verb}
                    </button>
                    <button
                        type="button"
                        onClick={() => void actions.cancel(action.id)}
                        className="rounded-full border border-line bg-surface px-4 py-1.5 hover:bg-soft"
                    >
                        Cancelar
                    </button>
                    <span className="text-muted">o di «sí» / «cancela»</span>
                </div>
            );
        case 'confirmed':
            return (
                <div className={base}>
                    <span className="text-ink">{countdownLabel ?? (verb === 'enviar' ? 'Se enviará' : 'Se aplicará')} en {secondsLeft} s</span>
                    <span aria-hidden className="h-1 w-24 overflow-hidden rounded-full bg-soft">
                        <span
                            className="block h-full bg-accent transition-[width] duration-200 ease-linear"
                            style={{ width: `${(secondsLeft / 8) * 100}%` }}
                        />
                    </span>
                    <button
                        type="button"
                        onClick={() => void actions.undo(action.id)}
                        className="rounded-full border border-line bg-surface px-4 py-1.5 hover:bg-soft"
                    >
                        Deshacer
                    </button>
                    <span className="text-muted">o di «deshaz»</span>
                </div>
            );
        case 'executing':
            return <div className={`${base} text-muted`}>{verb === 'enviar' ? 'Enviando…' : 'Aplicando…'}</div>;
        case 'executed':
            return (
                <div className={`${base} text-ok`}>
                    <span>✓ {doneLabel}</span>
                    {url && (
                        <a href={url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                            {linkLabel} ↗
                        </a>
                    )}
                </div>
            );
        case 'failed':
            return <div className={`${base} text-crit`}>No se ha podido aplicar. {action.error}</div>;
        default:
            return (
                <div className={`${base} text-muted`}>
                    {{ cancelled: 'Cancelado.', undone: verb === 'enviar' ? 'Deshecho: no se ha enviado.' : 'Deshecho: no se ha aplicado nada.', expired: 'Esta propuesta ha caducado.' }[action.status] ?? ''}
                </div>
            );
    }
}
