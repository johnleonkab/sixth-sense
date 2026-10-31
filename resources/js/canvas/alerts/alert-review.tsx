import { useEffect, useState } from 'react';

import ActionBar from '@/actions/action-bar';
import ProposalBar from '@/actions/proposal-bar';
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
import { browserTimezone, HttpError, postJson } from '@/lib/http';

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
    /** What the message asks of the person, as the classifier saw it. */
    actions?: ProposedAction[];
    /** What the search found for a question: one source, several to choose from, or nothing. */
    research?: {
        outcome: 'found' | 'several' | 'none';
        sources: { kind: string; title: string; url: string | null; updated_at: string | null; status?: string | null }[];
        stale: string | null;
    };
    /** Where the facts come from. */
    sources?: { kind: string; title: string; url: string | null; updated_at: string | null }[];
    /** Whom it goes to, when outside the person's circle, and the internal data the draft carries. */
    recipient?: { scope: 'other_team' | 'external'; label: string; internal: string[] } | null;
};

const updatedFormat = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });

type ProposedAction = {
    key: string;
    class: 'call' | 'task' | 'question' | 'reply';
    confidence: 'sure' | 'doubt';
    summary: string;
    doubt?: string;
    data?: {
        /** What a call still needs before looking for a slot. */
        needs?: ('people' | 'duration')[];
        candidates?: { name: string; checked: boolean }[];
        people?: string[];
        duration_minutes?: number;
    };
};

const callLengths = [15, 30, 45, 60];

const taskLengths = [15, 30, 45, 60, 90, 120];

const slotDay = new Intl.DateTimeFormat('es-ES', { weekday: 'short', day: 'numeric' });

const slotTime = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

/**
 * What a call is missing, settled with taps: who goes (the people in the
 * conversation ticked, usual colleagues suggested) and how long it lasts.
 */
function CallNeeds({ action, busy, onSettle }: { action: ProposedAction; busy: boolean; onSettle: (changes: { people?: string[]; duration_minutes?: number }) => void }) {
    const needs = action.data?.needs ?? [];
    const [people, setPeople] = useState<string[]>((action.data?.candidates ?? []).filter((candidate) => candidate.checked).map((candidate) => candidate.name));
    const [minutes, setMinutes] = useState<number | null>(action.data?.duration_minutes ?? null);
    const ready = (!needs.includes('people') || people.length > 0) && (!needs.includes('duration') || minutes !== null);

    return (
        <div className="mt-2 grid gap-2">
            {needs.includes('people') && (
                <fieldset>
                    <legend className="text-[12px] text-muted">¿Quiénes van?</legend>
                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                        {(action.data?.candidates ?? []).map((candidate) => (
                            <label key={candidate.name} className="flex items-center gap-1.5 text-[13px]">
                                <input
                                    type="checkbox"
                                    checked={people.includes(candidate.name)}
                                    onChange={() => setPeople((current) => (current.includes(candidate.name) ? current.filter((name) => name !== candidate.name) : [...current, candidate.name]))}
                                    className="accent-[var(--color-accent)]"
                                />
                                <span className={candidate.checked ? '' : 'text-muted'}>{candidate.name}</span>
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}
            {needs.includes('duration') && (
                <div role="group" aria-label="Duración" className="flex flex-wrap items-center gap-1.5">
                    <span className="text-[12px] text-muted">¿Cuánto dura?</span>
                    {callLengths.map((length) => (
                        <Button key={length} variant={minutes === length ? 'primary' : 'secondary'} disabled={busy} onClick={() => setMinutes(length)}>
                            {length} min
                        </Button>
                    ))}
                </div>
            )}
            <div>
                <Button
                    variant="secondary"
                    disabled={busy || !ready}
                    onClick={() => onSettle({ ...(needs.includes('people') ? { people } : {}), ...(needs.includes('duration') && minutes !== null ? { duration_minutes: minutes } : {}) })}
                >
                    Buscar hueco
                </Button>
            </div>
        </div>
    );
}

const actionClass: Record<ProposedAction['class'], string> = {
    call: 'Llamada',
    task: 'Tarea',
    question: 'Pregunta',
    reply: 'Respuesta',
};

/** A part of the one-tap "sí" the person can leave out. */
type Part = 'reply' | 'relay' | 'task';

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

type DiscardReason = 'not_important' | 'not_for_me' | 'bad_proposal';

const discardReasons: { reason: DiscardReason; label: string }[] = [
    { reason: 'not_important', label: 'No es importante' },
    { reason: 'not_for_me', label: 'No es para mí' },
    { reason: 'bad_proposal', label: 'La propuesta está mal' },
];

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
    // The call or task for the calendar goes with the same "sí" as the reply.
    const bundlesTask = canSend && props.task?.status === 'proposed' && Boolean(props.task.pending_action_id);
    const parts: Part[] = [
        ...(props.proposal?.reply?.text || reaction ? (['reply'] as Part[]) : []),
        ...(relay ? (['relay'] as Part[]) : []),
        ...(bundlesTask ? (['task'] as Part[]) : []),
    ];
    const [excluded, setExcluded] = useState<Part[]>([]);
    const included = parts.filter((part) => !excluded.includes(part));
    const toggle = (part: Part) => setExcluded((current) => (current.includes(part) ? current.filter((other) => other !== part) : [...current, part]));
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
    // What goes out: the proposal as it is, or as the person edited it before sending.
    const [replyText, setReplyText] = useState(props.proposal?.reply?.text ?? '');
    const [relayText, setRelayText] = useState(props.proposal?.redirect?.text ?? '');
    const [editing, setEditing] = useState(false);

    // Internal data going outside the person's circle: the server asks for one more "sí".
    const [confirmInternal, setConfirmInternal] = useState<string | null>(null);

    const send = async (internalSeen = false) => {
        setBusy(true);
        setSendError(null);
        setConfirmInternal(null);

        try {
            setSent(
                (
                    await postJson<{ actions: ActionState[] }>(`/alerts/${alert.id}/reply`, {
                        timezone: browserTimezone(),
                        text: replyText !== (props.proposal?.reply?.text ?? '') ? replyText : null,
                        relay_text: relayText !== (props.proposal?.redirect?.text ?? '') ? relayText : null,
                        exclude: excluded,
                        confirm_internal: internalSeen,
                    })
                ).actions,
            );
            setEditing(false);
        } catch (error) {
            if (error instanceof HttpError && error.status === 409) {
                setConfirmInternal(error.message);

                return;
            }

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
    // Discarding asks why, with one tap: each reason teaches a different part of the system.
    const [discarding, setDiscarding] = useState(false);

    const change = (outcome: 'done' | 'dismissed' | 'reopen', reason?: DiscardReason) => {
        setBusy(true);
        setDiscarding(false);
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
        runTool('resolve_alert', { alert_id: alert.id, outcome, ...(reason ? { reason } : {}) });
    };

    /** A doubt of the classifier, settled with one tap: "sí" prepares it, "no" takes it off. */
    const taskAction = (props.proposal?.actions ?? []).find((action) => action.class === 'task' && action.confidence === 'sure');
    const questionAction = (props.proposal?.actions ?? []).find((action) => action.class === 'question');
    const research = props.proposal?.research;

    const settle = (action: ProposedAction, answer: 'yes' | 'no' | null, changes: { people?: string[]; duration_minutes?: number; slot?: number; choice?: number } = {}) => {
        setBusy(true);
        runTool('settle_alert_action', { alert_id: alert.id, action: action.key, ...(answer ? { answer } : {}), ...changes });
    };

    /** Ticked, it goes out with the "sí"; shown only when the "sí" covers more than one thing. */
    const includeBox = (part: Part, label: string) =>
        parts.length > 1 && !sent ? (
            <label className="flex items-center gap-1.5 text-[12px] text-muted">
                <input type="checkbox" checked={!excluded.includes(part)} onChange={() => toggle(part)} className="accent-[var(--color-accent)]" />
                {label}
            </label>
        ) : (
            <p className="text-[12px] text-muted">{label}</p>
        );

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
                                    {includeBox('reply', audience[props.proposal.reply.to])}
                                    {editing ? (
                                        <textarea
                                            aria-label="Respuesta"
                                            value={replyText}
                                            onChange={(event) => setReplyText(event.target.value)}
                                            rows={Math.min(10, Math.max(3, replyText.split('\n').length + 1))}
                                            className="mt-1 w-full resize-y rounded-md border border-line bg-surface px-2.5 py-2 text-[13.5px] leading-[1.5] outline-none focus:border-accent"
                                        />
                                    ) : (
                                        <p className="mt-1 max-h-[22vh] overflow-auto text-[13.5px] leading-[1.5] whitespace-pre-wrap">{replyText}</p>
                                    )}
                                </div>
                            )}

                            {reaction && (
                                <div className="flex items-center gap-2.5 rounded-lg bg-soft px-3 py-2.5">
                                    <span aria-hidden className="text-[20px] leading-none">
                                        {reaction.emoji}
                                    </span>
                                    {includeBox('reply', 'Reaccionar a su mensaje, sin escribir nada')}
                                </div>
                            )}

                            {props.proposal.redirect?.text && (
                                <div className="rounded-lg bg-soft px-3 py-2.5">
                                    {includeBox('relay', `Pasárselo a ${props.proposal.redirect.to ?? 'otra persona'}`)}
                                    {editing && slack ? (
                                        <textarea
                                            aria-label="Mensaje para pasarlo"
                                            value={relayText}
                                            onChange={(event) => setRelayText(event.target.value)}
                                            rows={Math.min(8, Math.max(3, relayText.split('\n').length + 1))}
                                            className="mt-1 w-full resize-y rounded-md border border-line bg-surface px-2.5 py-2 text-[13.5px] leading-[1.5] outline-none focus:border-accent"
                                        />
                                    ) : (
                                        <p className="mt-1 max-h-[16vh] overflow-auto text-[13.5px] leading-[1.5] whitespace-pre-wrap">{relayText}</p>
                                    )}
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
                                        <Button variant="primary" disabled={busy || included.length === 0} onClick={() => void send()}>
                                            {busy
                                                ? 'Enviando…'
                                                : included.length === 1 && included[0] === 'task'
                                                  ? props.task?.kind === 'meeting'
                                                      ? 'Convocar'
                                                      : 'Reservar'
                                                  : included.includes('task')
                                                    ? `${sendLabel} y ${props.task?.kind === 'meeting' ? 'convocar' : 'reservar'}`
                                                    : sendLabel}
                                        </Button>
                                        {(props.proposal.reply || relay) && !editing && (
                                            <Button variant="secondary" disabled={busy} onClick={() => setEditing(true)}>
                                                Modificar
                                            </Button>
                                        )}
                                        <span className="text-[12px] text-muted">{editing ? 'Cambia el texto y envía' : 'o dime qué cambio'}</span>
                                        {confirmInternal && (
                                            <span className="flex basis-full flex-wrap items-center gap-2 text-[12.5px] text-warn">
                                                {confirmInternal}
                                                <Button variant="secondary" destructive disabled={busy} onClick={() => void send(true)}>
                                                    Enviar igualmente
                                                </Button>
                                            </span>
                                        )}
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

                            {(props.proposal.actions ?? []).filter((action) => action.class !== 'reply').length > 0 && (
                                <ul aria-label="Acciones" className="grid gap-1.5 text-[13px]">
                                    {(props.proposal.actions ?? [])
                                        .filter((action) => action.class !== 'reply')
                                        .map((action) => (
                                            <li key={action.key} className={action.confidence === 'doubt' ? 'rounded-lg border border-dashed border-line px-3 py-2' : ''}>
                                                <span className="mr-1.5 text-[11.5px] font-medium tracking-wide text-muted uppercase">{actionClass[action.class]}</span>
                                                {action.summary}
                                                {action.confidence === 'doubt' && pending && (
                                                    <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                                                        <span className="text-accent">{action.doubt}</span>
                                                        <Button variant="secondary" disabled={busy} onClick={() => settle(action, 'yes')}>
                                                            Sí
                                                        </Button>
                                                        <Button variant="tertiary" disabled={busy} onClick={() => settle(action, 'no')}>
                                                            No
                                                        </Button>
                                                    </span>
                                                )}
                                                {action.confidence === 'sure' && pending && (action.data?.needs ?? []).length > 0 && (
                                                    <CallNeeds action={action} busy={busy} onSettle={(changes) => settle(action, null, changes)} />
                                                )}
                                            </li>
                                        ))}
                                </ul>
                            )}

                            {props.proposal.recipient && (
                                <p className="text-[12.5px] text-warn">
                                    {props.proposal.recipient.label}
                                    {props.proposal.recipient.internal.length > 0 && `: la respuesta lleva datos internos (${props.proposal.recipient.internal.join(', ')}).`}
                                </p>
                            )}

                            {(props.proposal.sources ?? []).length > 0 && research?.outcome !== 'found' && (
                                <p className="text-[12px] text-muted">
                                    Fuentes:{' '}
                                    {(props.proposal.sources ?? []).map((source, position) => (
                                        <span key={source.title}>
                                            {position > 0 && ' · '}
                                            {source.url ? (
                                                <a href={source.url} target="_blank" rel="noreferrer noopener" className="hover:text-accent hover:underline">
                                                    {source.title}
                                                </a>
                                            ) : (
                                                source.title
                                            )}
                                            {source.updated_at && ` (${updatedFormat.format(new Date(source.updated_at))})`}
                                        </span>
                                    ))}
                                </p>
                            )}

                            {research?.outcome === 'several' && questionAction && pending && (
                                <div role="group" aria-label="Candidatos" className="grid gap-1.5">
                                    {research.sources.map((source, position) => (
                                        <Button key={source.title} variant="secondary" disabled={busy} onClick={() => settle(questionAction, null, { choice: position })}>
                                            {source.title}
                                        </Button>
                                    ))}
                                </div>
                            )}

                            {research?.outcome === 'found' && research.sources[0] && (
                                <p className="text-[12.5px] text-muted">
                                    Fuente:{' '}
                                    {research.sources[0].url ? (
                                        <a href={research.sources[0].url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                                            {research.sources[0].title}
                                        </a>
                                    ) : (
                                        research.sources[0].title
                                    )}
                                    {research.sources[0].status && ` · ${research.sources[0].status}`}
                                    {research.sources[0].updated_at && ` · actualizado el ${updatedFormat.format(new Date(research.sources[0].updated_at))}`}
                                </p>
                            )}

                            {research?.stale && <p className="text-[12.5px] text-warn">{research.stale}</p>}

                            {research?.outcome === 'none' && <p className="text-[12.5px] text-warn">No he encontrado la respuesta en Jira, el archivo ni Notion.</p>}

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
                    {/* Work asked for in a message: which of its slots, and how long, with one tap each. */}
                    {pending && taskAction && props.task.kind === 'task' && props.task.status === 'proposed' && !sent && (
                        <div className="mt-2 grid gap-1.5">
                            {(props.task.options ?? []).length > 1 && (
                                <div role="group" aria-label="Hueco" className="flex flex-wrap items-center gap-1.5">
                                    <span className="text-[12px] text-muted">Otro hueco:</span>
                                    {(props.task.options ?? []).map(([start], position) => (
                                        <Button
                                            key={start}
                                            variant={start === props.task?.starts_at ? 'primary' : 'secondary'}
                                            disabled={busy || start === props.task?.starts_at}
                                            onClick={() => settle(taskAction, null, { slot: position })}
                                        >
                                            {slotDay.format(new Date(start))} · {slotTime.format(new Date(start))}
                                        </Button>
                                    ))}
                                </div>
                            )}
                            <div role="group" aria-label="Duración" className="flex flex-wrap items-center gap-1.5">
                                <span className="text-[12px] text-muted">Duración:</span>
                                {taskLengths.map((length) => (
                                    <Button
                                        key={length}
                                        variant={length === props.task?.duration_minutes ? 'primary' : 'tertiary'}
                                        disabled={busy || length === props.task?.duration_minutes}
                                        onClick={() => settle(taskAction, null, { duration_minutes: length })}
                                    >
                                        {length < 60 ? `${length} min` : `${length / 60} h`.replace('.5', ',5')}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}
                    {/* Nothing reaches the calendar before the "sí". */}
                    {bundlesTask && !sent ? (
                        <div className="mt-2">{includeBox('task', 'Va con el «sí» de la propuesta')}</div>
                    ) : (
                        props.task.status === 'proposed' &&
                        props.task.pending_action_id && (
                        <ProposalBar
                            action={props.task.pending_action_id}
                            verb={props.task.kind === 'meeting' ? 'convocar' : 'reservar'}
                            done={props.task.kind === 'meeting' ? 'Reunión convocada' : 'En tu calendario'}
                        />
                        )
                    )}
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
                                {discarding ? (
                                    <>
                                        {discardReasons.map((option) => (
                                            <Button key={option.reason} size="small" disabled={busy} onClick={() => change('dismissed', option.reason)}>
                                                {option.label}
                                            </Button>
                                        ))}
                                        <Button size="small" variant="tertiary" disabled={busy} onClick={() => change('dismissed')}>
                                            Sin motivo
                                        </Button>
                                    </>
                                ) : (
                                    <Button disabled={busy} onClick={() => setDiscarding(true)}>
                                        Descartar
                                    </Button>
                                )}
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
