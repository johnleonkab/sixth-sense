import { useState } from 'react';

import ActionBar from '@/actions/action-bar';
import { useCanvas } from '@/canvas/canvas-context';
import { postJson } from '@/lib/http';
import type { WingmanAction } from '@/wingman/types';

const kindMark: Record<WingmanAction['kind'], string> = { meeting: '◷', message: '✉', issue: 'J', task: '✓' };

/** How the confirm button and the outcome read, by what the change does. */
const wording: Record<string, { verb: string; done: string; link: string; countdown: string }> = {
    'chat.send': { verb: 'enviar', done: 'Enviado', link: 'Abrir en Slack', countdown: 'Se enviará' },
    'mail.send': { verb: 'enviar', done: 'Enviado', link: 'Abrir en Gmail', countdown: 'Se enviará' },
    'calendar.change': { verb: 'convocar', done: 'Convocada', link: 'Abrir en Calendar', countdown: 'Se convocará' },
    'jira.create': { verb: 'crear', done: 'Creado', link: 'Abrir en Jira', countdown: 'Se creará' },
    'agenda.schedule': { verb: 'reservar', done: 'Hecho', link: 'Abrir en Calendar', countdown: 'Se reservará' },
};

function Row({ initial }: { initial: WingmanAction }) {
    const { actions } = useCanvas();
    const [item, setItem] = useState(initial);
    const [busy, setBusy] = useState(false);
    const words = wording[item.type] ?? { verb: 'aplicar', done: 'Hecho', link: 'Abrir', countdown: 'Se aplicará' };
    const live = item.action ? (actions.actions[item.action.id] ?? item.action) : null;
    // Cancelled, undone or expired: it can be reviewed again.
    const again = live !== null && ['cancelled', 'undone', 'expired'].includes(live.status);

    const call = async (verb: 'review' | 'dismiss' | 'restore') => {
        setBusy(true);

        try {
            const { action } = await postJson<{ action: WingmanAction }>(`/wingman/actions/${item.id}/${verb}`, {});
            setItem(action);

            if (action.action) {
                actions.track(action.action);
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <li className={`rounded-xl border border-line px-3.5 py-3 ${item.state === 'dismissed' ? 'opacity-50' : ''}`}>
            <div className="flex items-start gap-2.5">
                <span aria-hidden className="mt-px grid size-5 flex-none place-items-center rounded-[6px] bg-accent-soft text-[11px] font-semibold text-accent">
                    {kindMark[item.kind]}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-[14px] font-medium">{item.title}</p>
                    {item.detail && <p className="mt-0.5 text-[13px] whitespace-pre-line text-muted">{item.detail}</p>}
                </div>

                {(item.state === 'proposed' || again) && (
                    <div className="flex flex-none gap-1.5">
                        <button type="button" disabled={busy} onClick={() => void call('review')} className="rounded-full bg-accent px-3 py-1 text-[12.5px] font-medium text-accent-ink disabled:opacity-50">
                            Revisar
                        </button>
                        {!again && (
                            <button type="button" disabled={busy} onClick={() => void call('dismiss')} className="rounded-full border border-line px-3 py-1 text-[12.5px] text-muted hover:text-ink">
                                Descartar
                            </button>
                        )}
                    </div>
                )}

                {item.state === 'dismissed' && (
                    <button type="button" disabled={busy} onClick={() => void call('restore')} className="flex-none rounded-full border border-line px-3 py-1 text-[12.5px] text-muted hover:text-ink">
                        Recuperar
                    </button>
                )}
            </div>

            {live && !again && <ActionBar initial={live} verb={words.verb} doneLabel={words.done} linkLabel={words.link} countdownLabel={words.countdown} />}
        </li>
    );
}

/**
 * What is left to do after a call: each thing made ready, reviewed one by
 * one (Revisar → «Sí» → a few seconds to undo), or dropped.
 */
export default function MeetingActions({ items }: { items: WingmanAction[] }) {
    return (
        <ol className="space-y-2">
            {items.map((item) => (
                <Row key={item.id} initial={item} />
            ))}
        </ol>
    );
}
