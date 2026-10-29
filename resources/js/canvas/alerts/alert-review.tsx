import { useEffect, useState } from 'react';

import { StateChip } from '@/alerts/alert-center';
import { ALERT_CHANGED } from '@/alerts/use-alert-feed';
import { AgendaTaskSummary, type AgendaTaskCard } from '@/canvas/agenda/agenda-task';
import type { Alert } from '@/alerts/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import SlackCorrections from '@/canvas/slack/slack-corrections';
import type { CanvasComponent } from '@/canvas/types';
import { ServiceIconFor } from '@/components/service-icon';

type Proposal = {
    summary: string;
    reply: { to: 'sender' | 'all' | 'thread' | 'conversation'; text: string } | null;
    other_actions: string[];
    question: string | null;
    redirect?: { to: string | null; text: string } | null;
};

type AlertReviewProps = {
    task: AgendaTaskCard | null;
    alert: Alert;
    source_label: string;
    from: string | null;
    subject: string | null;
    where: string;
    text: string | null;
    conversation: { from: string; when: string; text: string }[];
    participants: string[];
    proposal: Proposal | null;
    is_email: boolean;
};

const audience: Record<NonNullable<Proposal['reply']>['to'], string> = {
    sender: 'Responder al remitente',
    all: 'Responder a todos',
    thread: 'Responder en el hilo',
    conversation: 'Responder en la conversación',
};

/**
 * One alert under review: the message on the left, and on the right how the
 * reply engine proposes to resolve it. The person changes it by voice; the
 * assistant then prepares each action as a draft to confirm.
 */
const resolutionText: Record<string, string> = {
    replied: 'Respondiste tú: se resolvió sola.',
    marked_done: 'La marcaste como resuelta.',
    dismissed: 'La descartaste: no hacía falta hacer nada.',
    superseded: 'La sustituyó un mensaje más reciente de la misma conversación.',
};

/** Where the alert stands, said in a line under its label. */
function stateLine(alert: Alert): string {
    switch (alert.state) {
        case 'judging':
            return 'Viendo si espera tu respuesta…';
        case 'routine':
            return 'No parece esperar tu respuesta. Si sí la espera, déjala pendiente.';
        case 'planning':
            return 'Preparando una propuesta…';
        case 'ready':
        case 'important':
            return 'Esperando tu respuesta.';
        default:
            return resolutionText[alert.resolution ?? ''] ?? '';
    }
}

const AlertReview: CanvasComponent<AlertReviewProps> = ({ index, ...props }) => {
    const { runTool } = useCanvas();
    const [alert, setAlert] = useState<Alert>(props.alert);
    const [busy, setBusy] = useState(false);
    const pending = alert.status === 'pending';
    const closed = alert.state === 'resolved' || alert.state === 'dismissed';

    // It changes while on screen: answered from Slack or Gmail, a proposal ready, closed by voice.
    useEffect(() => {
        const onChange = (event: Event) => {
            const changed = (event as CustomEvent<Alert>).detail;

            if (changed.id !== props.alert.id) {
                return;
            }

            setAlert(changed);

            // The proposal is ready, or the scheduler found time for it: show them whole.
            if ((changed.state === 'ready' && !props.proposal) || (changed.task?.status ?? null) !== (props.task?.status ?? null)) {
                runTool('review_alert', { alert_id: changed.id });
            }
        };

        window.addEventListener(ALERT_CHANGED, onChange);

        return () => window.removeEventListener(ALERT_CHANGED, onChange);
    }, [props.alert.id, props.proposal, props.task, runTool]);

    /** Shown at once; the canvas then comes back as the server left it. */
    const change = (outcome: 'done' | 'dismissed' | 'reopen') => {
        setBusy(true);
        setAlert((current) => ({
            ...current,
            ...(outcome === 'reopen'
                ? { status: 'pending', state: current.proposal ? 'ready' : 'important', resolution: null }
                : { status: outcome === 'done' ? 'resolved' : 'dismissed', state: outcome === 'done' ? 'resolved' : 'dismissed', resolution: outcome === 'done' ? 'marked_done' : 'dismissed' }),
        }));
        runTool('resolve_alert', { alert_id: alert.id, outcome });
    };

    return (
        <CanvasFrame
            index={index}
            title={props.subject ?? `${props.from} · ${props.where}`}
            source={props.source_label}
            aside={
                props.alert.external_url && (
                    <a href={props.alert.external_url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                        Abrir en {props.source_label}
                    </a>
                )
            }
        >
            <div
                key={alert.state}
                className={`alerts-row-in mb-4 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl px-3.5 py-2.5 ${closed ? 'bg-soft' : alert.state === 'routine' ? 'bg-soft/60' : 'bg-accent-soft/60'}`}
            >
                <StateChip state={alert.state} />
                <span className="text-[12.5px] text-muted">{stateLine(alert)}</span>
                {alert.why && (
                    <span className="flex basis-full items-center gap-2 text-[13px] text-ink/85">
                        <ServiceIconFor reference={alert.source} size={16} />
                        {alert.why}
                    </span>
                )}
            </div>

            <div className="grid gap-5 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <section aria-label="Mensaje" className="min-w-0">
                    {props.conversation.length > 0 && (
                        <ol className="mb-3 space-y-2 border-l-2 border-line pl-3 text-[12.5px] text-muted">
                            {props.conversation.map((message, position) => (
                                <li key={position}>
                                    <b className="font-medium text-ink/80">{message.from}</b> <span className="font-mono text-[11px] text-faint">{message.when}</span>
                                    <p className="line-clamp-3 whitespace-pre-wrap">{message.text}</p>
                                </li>
                            ))}
                        </ol>
                    )}
                    <p className="text-[12.5px] text-muted">
                        <b className="font-medium text-ink">{props.from}</b>
                        {!props.is_email && <> · {props.where}</>}
                        {props.is_email && props.participants.length > 0 && <> · con {props.participants.join(', ')}</>}
                    </p>
                    <p className="mt-1.5 max-h-[46vh] overflow-auto text-[14px] leading-[1.55] break-words whitespace-pre-wrap">{props.text}</p>
                </section>

                <section aria-label="Propuesta" className="min-w-0 rounded-xl bg-soft p-4">
                    <h4 className="text-[12px] font-medium tracking-wide text-muted uppercase">Propuesta</h4>

                    {props.proposal ? (
                        <>
                            <p className="mt-1.5 text-[15px] leading-[1.45]">{props.proposal.summary}</p>

                            {props.proposal.reply && (
                                <div className="mt-3 rounded-lg border border-line bg-surface p-3">
                                    <p className="text-[11.5px] text-muted">{audience[props.proposal.reply.to]}</p>
                                    <p className="mt-1 text-[13.5px] leading-[1.5] whitespace-pre-wrap">{props.proposal.reply.text}</p>
                                </div>
                            )}

                            {props.proposal.redirect?.text && (
                                <div className="mt-3 rounded-lg border border-line bg-surface p-3">
                                    <p className="text-[11.5px] text-muted">Pasárselo a {props.proposal.redirect.to ?? 'otra persona'}</p>
                                    <p className="mt-1 text-[13.5px] leading-[1.5] whitespace-pre-wrap">{props.proposal.redirect.text}</p>
                                </div>
                            )}

                            {props.proposal.other_actions.length > 0 && (
                                <ul className="mt-3 space-y-1 text-[13px]">
                                    {props.proposal.other_actions.map((action) => (
                                        <li key={action}>+ {action}</li>
                                    ))}
                                </ul>
                            )}

                            {props.proposal.question && <p className="mt-3 text-[13px] text-accent">{props.proposal.question}</p>}

                            {pending && <p className="mt-3 text-[12px] text-muted">Dime si lo hago así o qué cambio.</p>}
                        </>
                    ) : alert.state === 'planning' ? (
                        <div className="mt-2 space-y-2" aria-label="Preparando la propuesta">
                            <div className="alerts-shimmer h-4 w-4/5 rounded" />
                            <div className="alerts-shimmer h-16 rounded-lg" />
                            <div className="alerts-shimmer h-3 w-1/2 rounded" />
                        </div>
                    ) : (
                        <p className="mt-1.5 text-[13.5px] text-muted">{pending ? 'Sin propuesta todavía. Dime cómo quieres responder.' : 'Sin propuesta.'}</p>
                    )}

                    {pending && props.source_label === 'Slack' && (
                        <SlackCorrections onCorrect={(correction) => runTool('correct_slack_triage', { alert_id: alert.id, correction })} />
                    )}
                </section>
            </div>

            {props.task && (
                <section aria-label="Agendado" className="mt-4 rounded-xl border border-line p-4">
                    <h4 className="mb-2 text-[12px] font-medium tracking-wide text-muted uppercase">Agendador · {props.task.title}</h4>
                    <AgendaTaskSummary task={props.task} compact />
                </section>
            )}

            <div className="mt-4 flex items-center justify-end gap-2 text-[12.5px]">
                {busy && <span aria-hidden className="mr-auto size-3 animate-spin rounded-full border-[1.5px] border-muted border-t-transparent" />}
                {pending ? (
                    <>
                        <button type="button" disabled={busy} onClick={() => change('dismissed')} className="rounded-full border border-line px-3 py-1 hover:bg-soft disabled:opacity-50">
                            Descartar
                        </button>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => change('done')}
                            className="rounded-full bg-ink px-3 py-1 font-medium text-bg hover:opacity-90 disabled:opacity-50"
                        >
                            Ya está resuelta
                        </button>
                    </>
                ) : (
                    alert.state !== 'judging' && (
                        <button type="button" disabled={busy} onClick={() => change('reopen')} className="rounded-full border border-line px-3 py-1 hover:bg-soft disabled:opacity-50">
                            {closed ? 'Reabrir' : 'Dejar pendiente'}
                        </button>
                    )
                )}
            </div>
        </CanvasFrame>
    );
};

export default AlertReview;
