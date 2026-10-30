import { useEffect, useState } from 'react';

import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { StateChip } from '@/alerts/alert-center';
import { ALERT_CHANGED } from '@/alerts/use-alert-feed';
import { AgendaTaskSummary, type AgendaTaskCard } from '@/canvas/agenda/agenda-task';
import type { Alert } from '@/alerts/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import SlackCorrections from '@/canvas/slack/slack-corrections';
import type { CanvasComponent } from '@/canvas/types';
import { Button, Buttons } from '@/components/button';
import { browserTimezone, postJson } from '@/lib/http';

type Proposal = {
    summary: string;
    reply: {
        to: 'sender' | 'all' | 'thread' | 'conversation';
        text: string;
    } | null;
    other_actions: string[];
    question: string | null;
    redirect?: { to: string | null; text: string } | null;
    /** In Slack, a reaction instead of writing back. */
    reaction?: { name: string; emoji: string } | null;
    /** What the search found, for the person: never part of what is sent. */
    context?: string | null;
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
    const [sent, setSent] = useState<ActionState[] | null>(null);
    const [sendError, setSendError] = useState<string | null>(null);
    const slack = props.source_label === 'Slack';
    const relay = slack && props.proposal?.redirect?.text && props.proposal.redirect.to ? props.proposal.redirect : null;
    const reaction = slack && !props.proposal?.reply?.text ? (props.proposal?.reaction ?? null) : null;
    const canSend = pending && (slack || props.is_email) && Boolean(props.proposal?.reply?.text || relay || reaction);
    const sendLabel = props.is_email
        ? props.proposal?.reply?.to === 'all'
            ? 'Responder a todos'
            : 'Enviar respuesta'
        : reaction && relay
          ? `Reaccionar ${reaction.emoji} y enviar a ${relay.to}`
          : reaction
            ? `Reaccionar con ${reaction.emoji}`
            : props.proposal?.reply?.text && relay
              ? 'Enviar los dos'
              : relay
                ? `Enviar a ${relay.to}`
                : 'Enviar en Slack';

    /** One tap is the "sí": it goes out when the undo window closes. */
    const send = async () => {
        setBusy(true);
        setSendError(null);

        try {
            setSent((await postJson<{ actions: ActionState[] }>(`/alerts/${alert.id}/reply`, { timezone: browserTimezone() })).actions);
        } catch (error) {
            setSendError(error instanceof Error ? error.message : 'No se ha podido enviar.');
        } finally {
            setBusy(false);
        }
    };

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
                ? {
                      status: 'pending',
                      state: current.proposal ? 'ready' : 'important',
                      resolution: null,
                  }
                : {
                      status: outcome === 'done' ? 'resolved' : 'dismissed',
                      state: outcome === 'done' ? 'resolved' : 'dismissed',
                      resolution: outcome === 'done' ? 'marked_done' : 'dismissed',
                  }),
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
            {/* Where it stands and why it matters, in one line: the title already says who and where. */}
            <div key={alert.state} className="alerts-row-in mb-4 flex items-start gap-2.5">
                <span className="mt-px flex-none">
                    <StateChip state={alert.state} />
                </span>
                <p className="min-w-0 text-[14px] leading-[1.45] text-ink/85">
                    {alert.why}
                    {!pending && stateLine(alert) && (
                        <span className="text-muted">
                            {alert.why ? ' · ' : ''}
                            {stateLine(alert)}
                        </span>
                    )}
                    {pending && !alert.why && <span className="text-muted">{stateLine(alert)}</span>}
                </p>
            </div>

            <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                <section aria-label="Mensaje" className="min-w-0">
                    {props.is_email && (
                        <p className="mb-1.5 text-[12.5px] text-muted">
                            <b className="font-medium text-ink">{props.from}</b>
                            {props.participants.length > 0 && <> · con {props.participants.join(', ')}</>}
                        </p>
                    )}
                    <div className="max-h-[40vh] overflow-auto">
                        {props.conversation.length > 0 && (
                            <ol className="mb-3 space-y-2 border-l-2 border-line pl-3 text-[12.5px] text-muted">
                                {props.conversation.map((message, position) => (
                                    <li key={position}>
                                        <b className="font-medium text-ink/80">{message.from}</b>{' '}
                                        <span className="font-mono text-[11px] text-faint">{message.when}</span>
                                        <p className="line-clamp-3 whitespace-pre-wrap">{message.text}</p>
                                    </li>
                                ))}
                            </ol>
                        )}
                        {!props.is_email && props.conversation.length > 0 && <p className="text-[12.5px] font-medium">{props.from}</p>}
                        <p className="text-[14px] leading-[1.55] break-words whitespace-pre-wrap">{props.text}</p>
                    </div>
                </section>

                <section aria-label="Propuesta" className="grid min-w-0 content-start gap-2.5 rounded-2xl border border-line bg-surface px-4 py-3.5">
                    {props.proposal ? (
                        <>
                            <h4 className="text-[14px] leading-[1.45] font-medium">{props.proposal.summary}</h4>

                            {props.proposal.reply && (
                                <div className="rounded-lg bg-soft px-3 py-2.5">
                                    <p className="text-[12px] text-muted">{audience[props.proposal.reply.to]}</p>
                                    <p className="mt-1 max-h-[22vh] overflow-auto text-[13.5px] leading-[1.5] whitespace-pre-wrap">
                                        {props.proposal.reply.text}
                                    </p>
                                </div>
                            )}

                            {reaction && (
                                <div className="flex items-center gap-2.5 rounded-lg bg-soft px-3 py-2.5">
                                    <span aria-hidden className="text-[20px] leading-none">
                                        {reaction.emoji}
                                    </span>
                                    <p className="text-[13px] text-muted">Reaccionar a su mensaje, sin escribir nada</p>
                                </div>
                            )}

                            {props.proposal.redirect?.text && (
                                <div className="rounded-lg bg-soft px-3 py-2.5">
                                    <p className="text-[12px] text-muted">Pasárselo a {props.proposal.redirect.to ?? 'otra persona'}</p>
                                    <p className="mt-1 max-h-[16vh] overflow-auto text-[13.5px] leading-[1.5] whitespace-pre-wrap">
                                        {props.proposal.redirect.text}
                                    </p>
                                </div>
                            )}

                            {sent ? (
                                <div className="grid gap-1">
                                    {sent.map((action) => (
                                        <div key={action.id}>
                                            <p className="text-[12px] text-muted">{action.label}</p>
                                            <ActionBar initial={action} verb="enviar" doneLabel="Enviado" linkLabel={props.is_email ? 'Abrir en Gmail' : 'Abrir en Slack'} />
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                canSend && (
                                    <Buttons className="gap-x-3 gap-y-1.5">
                                        <Button variant="primary" disabled={busy} onClick={() => void send()}>
                                            {busy ? 'Enviando…' : sendLabel}
                                        </Button>
                                        <span className="text-[12px] text-muted">o dime qué cambio</span>
                                        {sendError && <span className="basis-full text-[12.5px] text-crit">{sendError}</span>}
                                    </Buttons>
                                )
                            )}

                            {props.proposal.other_actions.length > 0 && (
                                <ul className="space-y-1 text-[13px]">
                                    {props.proposal.other_actions.map((action) => (
                                        <li key={action}>+ {action}</li>
                                    ))}
                                </ul>
                            )}

                            {props.proposal.question && <p className="text-[13px] text-accent">{props.proposal.question}</p>}

                            {props.proposal.context && props.proposal.context !== props.proposal.question && <p className="text-[12px] leading-[1.45] text-muted">{props.proposal.context}</p>}
                        </>
                    ) : alert.state === 'planning' ? (
                        <div className="space-y-2" aria-label="Preparando la propuesta">
                            <div className="alerts-shimmer h-4 w-4/5 rounded" />
                            <div className="alerts-shimmer h-16 rounded-lg" />
                            <div className="alerts-shimmer h-3 w-1/2 rounded" />
                        </div>
                    ) : (
                        <p className="text-[13.5px] text-muted">{pending ? 'Sin propuesta todavía. Dime cómo quieres responder.' : 'Sin propuesta.'}</p>
                    )}
                </section>
            </div>

            {props.task && (
                <section aria-label="Agendado" className="mt-4 rounded-xl border border-line p-4">
                    <h4 className="mb-2 text-[12px] font-medium tracking-wide text-muted uppercase">Agendador · {props.task.title}</h4>
                    <AgendaTaskSummary task={props.task} compact />
                </section>
            )}

            {/* Every action in one row: how Slack should treat this kind of message, and what to do with this one. */}
            {!sent && (
                <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3 text-[13px]">
                    {pending && props.source_label === 'Slack' && (
                        <SlackCorrections className="mr-auto" onCorrect={(correction) => runTool('correct_slack_triage', { alert_id: alert.id, correction })} />
                    )}
                    {busy && <span aria-hidden className="size-3 animate-spin rounded-full border-[1.5px] border-muted border-t-transparent" />}
                    <Buttons className="ml-auto">
                        {pending ? (
                            <>
                                <Button disabled={busy} onClick={() => change('dismissed')}>
                                    Descartar
                                </Button>
                                {/* With a reply ready to send, sending is the main thing to do. */}
                                <Button variant={canSend ? 'secondary' : 'primary'} disabled={busy} onClick={() => change('done')}>
                                    Ya está resuelta
                                </Button>
                            </>
                        ) : (
                            alert.state !== 'judging' && (
                                <Button disabled={busy} onClick={() => change('reopen')}>
                                    {closed ? 'Reabrir' : 'Dejar pendiente'}
                                </Button>
                            )
                        )}
                    </Buttons>
                </div>
            )}
        </CanvasFrame>
    );
};

export default AlertReview;
