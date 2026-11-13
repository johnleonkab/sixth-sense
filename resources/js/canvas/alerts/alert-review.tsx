import { useEffect, useState } from 'react';

import ActionBar from '@/actions/action-bar';
import ProposalBar from '@/actions/proposal-bar';
import type { ActionState } from '@/actions/types';
import AlertCallout from '@/alerts/alert-callout';
import { AlertCtaBar, joinEs, PlanStep } from '@/alerts/alert-plan';
import AlertThread from '@/alerts/alert-thread';
import { ALERT_CHANGED } from '@/alerts/use-alert-feed';
import { AgendaTaskSummary, type AgendaTaskCard } from '@/canvas/agenda/agenda-task';
import type { Alert } from '@/alerts/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import SlackCorrections from '@/canvas/slack/slack-corrections';
import type { CanvasComponent } from '@/canvas/types';
import { Button } from '@/components/button';
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
        <div className="mt-2 grid gap-3">
            {needs.includes('people') && (
                <fieldset>
                    <legend className="text-[14px] text-muted md:text-[12px]">¿Quiénes van?</legend>
                    <div className="mt-1.5 flex flex-col gap-1 md:flex-row md:flex-wrap md:gap-x-3 md:gap-y-1">
                        {(action.data?.candidates ?? []).map((candidate) => (
                            <label key={candidate.name} className="flex min-h-11 items-center gap-2.5 text-[16px] md:min-h-0 md:gap-1.5 md:text-[13px]">
                                <input
                                    type="checkbox"
                                    checked={people.includes(candidate.name)}
                                    onChange={() => setPeople((current) => (current.includes(candidate.name) ? current.filter((name) => name !== candidate.name) : [...current, candidate.name]))}
                                    className="size-[18px] accent-[var(--color-accent)] md:size-[13px]"
                                />
                                <span className={candidate.checked ? '' : 'text-muted'}>{candidate.name}</span>
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}
            {needs.includes('duration') && (
                <div role="group" aria-label="Duración" className="flex flex-wrap items-center gap-2">
                    <span className="w-full text-[14px] text-muted md:w-auto md:text-[12px]">¿Cuánto dura?</span>
                    {callLengths.map((length) => (
                        <Button key={length} size="large" variant={minutes === length ? 'primary' : 'secondary'} disabled={busy} onClick={() => setMinutes(length)}>
                            {length} min
                        </Button>
                    ))}
                </div>
            )}
            <div>
                <Button
                    variant="primary"
                    size="large"
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
 * One alert under review: the ask first, then the conversation, then the
 * plan. The person changes it by voice; the assistant then prepares each
 * action as a draft to confirm.
 */
const resolutionText: Record<string, string> = {
    replied: 'Respondiste tú: se resolvió sola.',
    marked_done: 'La marcaste como resuelta.',
    dismissed: 'La descartaste: no hacía falta hacer nada.',
    superseded: 'La sustituyó un mensaje más reciente de la misma conversación.',
    someone_else: 'Otra persona lo resolvió en el hilo: ya no hacía falta.',
    call_happened: 'La llamada que pedía ya ha pasado.',
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
    const partLabel = (part: Part): string => {
        if (part === 'reply') {
            if (reaction) {
                return `Reaccionar con ${reaction.emoji}`;
            }

            if (props.is_email) {
                return props.proposal?.reply?.to === 'all' ? 'Responder a todos' : 'Enviar la respuesta';
            }

            return 'Enviar en Slack';
        }

        if (part === 'relay') {
            return `Enviar a ${relay?.to}`;
        }

        return props.task?.kind === 'meeting' ? 'Convocar la reunión' : 'Reservar el hueco';
    };
    const primaryLabel = busy ? 'Enviando…' : included.length === 0 ? 'Elige qué hacer' : joinEs(included.map(partLabel));
    const toggleable = parts.length > 1 && !sent;

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

    const extraActions = (props.proposal?.actions ?? []).filter((action) => action.class !== 'reply');
    const numbered =
        Number(Boolean(props.proposal?.reply || reaction)) +
        Number(Boolean(props.proposal?.redirect?.text)) +
        (props.proposal?.other_actions?.length ?? 0) +
        extraActions.length +
        Number(Boolean(props.task));
    let step = 0;
    const mark = () => (numbered > 1 ? ++step : undefined);
    const draftClass =
        'w-full resize-y rounded-md border border-line bg-surface px-3 py-2.5 text-[16px] leading-[1.5] outline-none focus:border-accent md:text-[13.5px]';

    return (
        <CanvasFrame
            index={index}
            title="Aviso"
            source={props.source_label}
            aside={
                props.alert.external_url && (
                    <a href={props.alert.external_url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                        Abrir en {props.source_label}
                    </a>
                )
            }
        >
            <AlertCallout
                ask={alert.state === 'judging' ? 'Viendo si espera tu respuesta…' : alert.why || alert.title}
                actor={props.from ?? alert.actor_name}
                source={props.source_label}
                where={props.is_email ? props.subject : props.where}
                deadline={alert.deadline}
            >
                {!pending && stateLine(alert) && <p className="mt-2 text-[15px] text-muted md:text-[13px]">{stateLine(alert)}</p>}
                {pending && slack && (
                    <SlackCorrections className="mt-3" onCorrect={(correction) => runTool('correct_slack_triage', { alert_id: alert.id, correction })} />
                )}
            </AlertCallout>

            <div className="mt-6 grid gap-8 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] md:items-start">
                <AlertThread
                    messages={props.conversation}
                    latestFrom={props.from}
                    latestText={props.text}
                    participants={props.is_email ? props.participants : []}
                    source={props.source_label}
                />

                <section aria-label="Qué voy a hacer" className="grid min-w-0 content-start gap-3">
                    <h4 className="text-[13px] font-medium tracking-wide text-muted uppercase">Qué voy a hacer</h4>
                    {props.proposal ? (
                        <>
                            <p className="text-[17px] leading-[1.35] font-medium md:text-[15px]">{props.proposal.summary}</p>

                            {props.proposal.reply && (
                                <PlanStep
                                    n={mark()}
                                    kind={audience[props.proposal.reply.to]}
                                    included={!excluded.includes('reply')}
                                    onToggle={toggleable ? () => toggle('reply') : undefined}
                                >
                                    {editing ? (
                                        <textarea
                                            aria-label="Respuesta"
                                            value={replyText}
                                            onChange={(event) => setReplyText(event.target.value)}
                                            rows={Math.min(10, Math.max(3, replyText.split('\n').length + 1))}
                                            className={draftClass}
                                        />
                                    ) : (
                                        <p className="max-h-[22vh] overflow-auto text-[16px] leading-[1.5] whitespace-pre-wrap md:text-[13.5px]">{replyText}</p>
                                    )}
                                </PlanStep>
                            )}

                            {reaction && (
                                <PlanStep
                                    n={mark()}
                                    kind={`Reaccionar con ${reaction.emoji}`}
                                    included={!excluded.includes('reply')}
                                    onToggle={toggleable ? () => toggle('reply') : undefined}
                                >
                                    <p className="text-[28px] leading-none" aria-hidden>
                                        {reaction.emoji}
                                    </p>
                                    <p className="mt-1.5 text-[15px] text-muted md:text-[13px]">Sin escribir nada.</p>
                                </PlanStep>
                            )}

                            {props.proposal.redirect?.text && (
                                <PlanStep
                                    n={mark()}
                                    kind={`Pasárselo a ${props.proposal.redirect.to ?? 'otra persona'}`}
                                    included={!excluded.includes('relay')}
                                    onToggle={toggleable ? () => toggle('relay') : undefined}
                                >
                                    {editing && slack ? (
                                        <textarea
                                            aria-label="Mensaje para pasarlo"
                                            value={relayText}
                                            onChange={(event) => setRelayText(event.target.value)}
                                            rows={Math.min(8, Math.max(3, relayText.split('\n').length + 1))}
                                            className={draftClass}
                                        />
                                    ) : (
                                        <p className="max-h-[16vh] overflow-auto text-[16px] leading-[1.5] whitespace-pre-wrap md:text-[13.5px]">{relayText}</p>
                                    )}
                                </PlanStep>
                            )}

                            {(props.proposal.other_actions ?? []).map((action) => (
                                <PlanStep key={action} n={mark()} kind={action} />
                            ))}

                            {extraActions.map((action) => (
                                <PlanStep key={action.key} n={mark()} kind={`${actionClass[action.class]} · ${action.summary}`}>
                                    {action.confidence === 'doubt' && pending && (
                                        <div className="grid gap-2">
                                            <p className="text-[15px] text-accent md:text-[13px]">{action.doubt}</p>
                                            <Button variant="primary" size="large" disabled={busy} onClick={() => settle(action, 'yes')}>
                                                Sí, preparar esto
                                            </Button>
                                            <Button variant="secondary" size="large" disabled={busy} onClick={() => settle(action, 'no')}>
                                                No, quitarlo
                                            </Button>
                                        </div>
                                    )}
                                    {action.confidence === 'sure' && pending && (action.data?.needs ?? []).length > 0 && (
                                        <CallNeeds action={action} busy={busy} onSettle={(changes) => settle(action, null, changes)} />
                                    )}
                                </PlanStep>
                            ))}

                            {sent && (
                                <div className="grid gap-2">
                                    {sent.map((action) => (
                                        <div key={action.id}>
                                            <p className="text-[14px] text-muted md:text-[12px]">{action.label}</p>
                                            <ActionBar initial={action} verb="enviar" doneLabel="Enviado" linkLabel={props.is_email ? 'Abrir en Gmail' : 'Abrir en Slack'} />
                                        </div>
                                    ))}
                                </div>
                            )}

                            {props.proposal.recipient && (
                                <p className="text-[15px] text-warn md:text-[12.5px]">
                                    {props.proposal.recipient.label}
                                    {props.proposal.recipient.internal.length > 0 && `: la respuesta lleva datos internos (${props.proposal.recipient.internal.join(', ')}).`}
                                </p>
                            )}

                            {(props.proposal.sources ?? []).length > 0 && research?.outcome !== 'found' && (
                                <p className="text-[14px] text-muted md:text-[12px]">
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
                                <div role="group" aria-label="Candidatos" className="grid gap-2">
                                    {research.sources.map((source, position) => (
                                        <Button key={source.title} variant="secondary" size="large" disabled={busy} onClick={() => settle(questionAction, null, { choice: position })}>
                                            {source.title}
                                        </Button>
                                    ))}
                                </div>
                            )}

                            {research?.outcome === 'found' && research.sources[0] && (
                                <p className="text-[15px] text-muted md:text-[12.5px]">
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

                            {research?.stale && <p className="text-[15px] text-warn md:text-[12.5px]">{research.stale}</p>}

                            {research?.outcome === 'none' && (
                                <p className="text-[15px] text-warn md:text-[12.5px]">No he encontrado la respuesta en Jira, el archivo ni Notion.</p>
                            )}

                            {props.proposal.question && <p className="text-[16px] text-accent md:text-[13px]">{props.proposal.question}</p>}

                            {props.proposal.context && props.proposal.context !== props.proposal.question && (
                                <p className="text-[15px] leading-[1.45] text-muted md:text-[12px]">{props.proposal.context}</p>
                            )}

                            {editing && <p className="text-[14px] text-muted md:text-[12px]">Cambia el texto y pulsa enviar.</p>}
                        </>
                    ) : alert.state === 'planning' ? (
                        <div className="space-y-2" aria-label="Preparando la propuesta">
                            <div className="alerts-shimmer h-5 w-4/5 rounded" />
                            <div className="alerts-shimmer h-20 rounded-2xl" />
                            <div className="alerts-shimmer h-4 w-1/2 rounded" />
                        </div>
                    ) : (
                        <p className="text-[16px] text-muted md:text-[13.5px]">{pending ? 'Sin propuesta todavía. Dime cómo quieres responder.' : 'Sin propuesta.'}</p>
                    )}
                </section>
            </div>

            {props.task && (
                <section aria-label="Agendado" className="mt-6">
                    <PlanStep
                        n={mark()}
                        kind={props.task.kind === 'meeting' ? `Reunión · ${props.task.title}` : `Hueco · ${props.task.title}`}
                        included={bundlesTask ? !excluded.includes('task') : undefined}
                        onToggle={bundlesTask && toggleable ? () => toggle('task') : undefined}
                    >
                        <AgendaTaskSummary task={props.task} compact />
                        {pending && taskAction && props.task.kind === 'task' && props.task.status === 'proposed' && !sent && (
                            <div className="mt-3 grid gap-3">
                                {(props.task.options ?? []).length > 1 && (
                                    <div role="group" aria-label="Hueco" className="flex flex-wrap items-center gap-2">
                                        <span className="w-full text-[14px] text-muted md:w-auto md:text-[12px]">Otro hueco:</span>
                                        {(props.task.options ?? []).map(([start], position) => (
                                            <Button
                                                key={start}
                                                size="large"
                                                variant={start === props.task?.starts_at ? 'primary' : 'secondary'}
                                                disabled={busy || start === props.task?.starts_at}
                                                onClick={() => settle(taskAction, null, { slot: position })}
                                            >
                                                {slotDay.format(new Date(start))} · {slotTime.format(new Date(start))}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                                <div role="group" aria-label="Duración" className="flex flex-wrap items-center gap-2">
                                    <span className="w-full text-[14px] text-muted md:w-auto md:text-[12px]">Duración:</span>
                                    {taskLengths.map((length) => (
                                        <Button
                                            key={length}
                                            size="large"
                                            variant={length === props.task?.duration_minutes ? 'primary' : 'secondary'}
                                            disabled={busy || length === props.task?.duration_minutes}
                                            onClick={() => settle(taskAction, null, { duration_minutes: length })}
                                        >
                                            {length < 60 ? `${length} min` : `${length / 60} h`.replace('.5', ',5')}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}
                        {!bundlesTask && props.task.status === 'proposed' && props.task.pending_action_id && (
                            <div className="mt-3">
                                <ProposalBar
                                    action={props.task.pending_action_id}
                                    verb={props.task.kind === 'meeting' ? 'convocar' : 'reservar'}
                                    done={props.task.kind === 'meeting' ? 'Reunión convocada' : 'En tu calendario'}
                                />
                            </div>
                        )}
                    </PlanStep>
                </section>
            )}

            {!sent && (pending || alert.state !== 'judging') && (
                <AlertCtaBar>
                    {canSend && (
                        <Button variant="primary" size="large" disabled={busy || included.length === 0} onClick={() => void send()}>
                            {primaryLabel}
                        </Button>
                    )}
                    {canSend && (props.proposal?.reply || relay) && !editing && (
                        <Button variant="secondary" size="large" disabled={busy} onClick={() => setEditing(true)}>
                            Cambiar el texto
                        </Button>
                    )}
                    {confirmInternal && (
                        <>
                            <p className="text-[15px] text-warn md:text-[13px]">{confirmInternal}</p>
                            <Button variant="secondary" size="large" destructive disabled={busy} onClick={() => void send(true)}>
                                Enviar igualmente
                            </Button>
                        </>
                    )}
                    {sendError && <p className="text-[15px] text-crit md:text-[13px]">{sendError}</p>}
                    {pending ? (
                        discarding ? (
                            <>
                                {discardReasons.map((option) => (
                                    <Button key={option.reason} size="large" disabled={busy} onClick={() => change('dismissed', option.reason)}>
                                        {option.label}
                                    </Button>
                                ))}
                                <Button size="large" variant="tertiary" disabled={busy} onClick={() => change('dismissed')}>
                                    Descartar sin motivo
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button variant={canSend ? 'secondary' : 'primary'} size="large" disabled={busy} onClick={() => change('done')}>
                                    Marcar como resuelta
                                </Button>
                                <Button variant="tertiary" size="large" destructive disabled={busy} onClick={() => setDiscarding(true)}>
                                    Descartar este aviso
                                </Button>
                            </>
                        )
                    ) : (
                        alert.state !== 'judging' && (
                            <Button size="large" disabled={busy} onClick={() => change('reopen')}>
                                {closed ? 'Reabrir este aviso' : 'Dejar pendiente'}
                            </Button>
                        )
                    )}
                </AlertCtaBar>
            )}
        </CanvasFrame>
    );
};

export default AlertReview;
