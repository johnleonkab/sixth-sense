import { sourceDot } from '@/alerts/alert-center';
import type { Alert } from '@/alerts/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

type Proposal = {
    summary: string;
    reply: { to: 'sender' | 'all' | 'thread' | 'conversation'; text: string } | null;
    other_actions: string[];
    question: string | null;
};

type AlertReviewProps = {
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
const AlertReview: CanvasComponent<AlertReviewProps> = ({ index, ...props }) => {
    const { runTool } = useCanvas();
    const pending = props.alert.status === 'pending';

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
            <p className="mb-4 flex items-center gap-2 text-[13px] text-muted">
                <i aria-hidden className={`size-[8px] rounded-[3px] ${sourceDot[props.alert.source]}`} />
                {props.alert.why}
            </p>

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
                    ) : (
                        <p className="mt-1.5 text-[13.5px] text-muted">Sin propuesta todavía. Dime cómo quieres responder.</p>
                    )}
                </section>
            </div>

            <div className="mt-4 flex justify-end gap-2 text-[12.5px]">
                {pending ? (
                    <>
                        <button
                            type="button"
                            onClick={() => runTool('resolve_alert', { alert_id: props.alert.id, outcome: 'dismissed' })}
                            className="rounded-full border border-line px-3 py-1 hover:bg-soft"
                        >
                            Descartar
                        </button>
                        <button
                            type="button"
                            onClick={() => runTool('resolve_alert', { alert_id: props.alert.id, outcome: 'done' })}
                            className="rounded-full border border-line px-3 py-1 hover:bg-soft"
                        >
                            Ya está resuelta
                        </button>
                    </>
                ) : (
                    <span className="text-muted">{props.alert.status === 'resolved' ? 'Resuelta' : props.alert.status === 'dismissed' ? 'Descartada' : ''}</span>
                )}
            </div>
        </CanvasFrame>
    );
};

export default AlertReview;
