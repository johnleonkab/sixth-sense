import { useState } from 'react';

import AlertCallout from '@/alerts/alert-callout';
import { AlertCtaBar, PlanStep, SectionMark } from '@/alerts/alert-plan';
import AlertThread from '@/alerts/alert-thread';
import type { Alert } from '@/alerts/types';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { Button } from '@/components/button';
import { HttpError, postJson } from '@/lib/http';

type Proposal = {
    id: number;
    status: string;
    text: string;
    deny_reason: string | null;
    error?: string | null;
    undo: boolean;
};

type CommsReviewProps = {
    alert: Alert;
    source_label: string;
    what: string | null;
    ask: string | null;
    ask_kind: 'summary' | 'words' | null;
    people: string[];
    sources: string[];
    requester: string | null;
    state: string | null;
    deadline: string | null;
    messages: { from: string; when: string; text: string }[];
    proposal: Proposal | null;
    question: { id: number; text: string } | null;
    reply_to: string | null;
};

const statusLine: Record<string, string> = {
    approved: 'Aprobada. Tienes unos segundos para deshacer.',
    executed: 'Ya se envió.',
    rejected: 'La rechazaste.',
    undone: 'La deshiciste: no se envió.',
    failed: 'No se pudo enviar.',
    expired: 'Caducó sin que nadie la decidiera.',
};

const draftClass =
    'w-full resize-y rounded-md border border-line bg-bg px-3 py-2.5 text-[16px] leading-[1.5] outline-none focus:border-accent md:text-[13.5px]';

/**
 * One alert from the communications pipeline: the ask first, then the
 * conversation, then the plan waiting for a yes. Nothing is sent until the
 * person approves it here.
 */
const CommsReview: CanvasComponent<CommsReviewProps> = ({ index, ...props }) => {
    const [proposal, setProposal] = useState<Proposal | null>(props.proposal);
    const [text, setText] = useState(props.proposal?.text ?? '');
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const open = proposal !== null && (proposal.status === 'pending' || proposal.status === 'edited');

    const decide = async (action: 'approve' | 'reject' | 'undo' | 'edit', next: Partial<Proposal>) => {
        if (!proposal) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            await postJson(`/comms/proposals/${proposal.id}/${action}`, action === 'edit' ? { text } : {});
            setProposal({ ...proposal, ...next });
            setEditing(false);
        } catch (failure) {
            setError(failure instanceof HttpError && failure.status === 422 ? 'No se puede enviar así.' : 'No ha salido bien. Prueba otra vez.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <CanvasFrame index={index} title="Aviso" source={props.source_label} showTitle={false} showSourceName={false} marks={props.sources}>
            <AlertCallout
                ask={props.ask}
                quoted={props.ask_kind === 'words'}
                people={props.people}
                sources={props.sources}
                actor={props.requester ?? props.alert.actor_name}
                deadline={props.deadline}
            >
                {props.state === 'settled' && <p className="mt-2 text-[15px] text-muted md:text-[13px]">Ya está resuelto.</p>}
            </AlertCallout>

            <div className="mt-6 grid gap-8">
                <AlertThread messages={props.messages} source={props.source_label} />

                <section aria-label="Qué voy a hacer" className="min-w-0">
                    <SectionMark label="Qué voy a hacer" kind="plan" />
                    {proposal ? (
                        <>
                            <ol className="list-none p-0">
                                <PlanStep n={1} kind={props.reply_to ? `Responder en ${props.source_label}` : 'Respuesta propuesta'}>
                                    {editing ? (
                                        <textarea
                                            aria-label="Texto de la propuesta"
                                            value={text}
                                            onChange={(event) => setText(event.target.value)}
                                            rows={Math.min(10, Math.max(3, text.split('\n').length + 1))}
                                            className={draftClass}
                                        />
                                    ) : (
                                        <p className="text-[16px] leading-[1.5] whitespace-pre-wrap md:text-[13.5px]">{text}</p>
                                    )}
                                </PlanStep>
                            </ol>
                            {proposal.deny_reason && <p className="mt-3 text-[15px] text-crit md:text-[13px]">No se envía: {proposal.deny_reason}</p>}
                            {!open && proposal.status === 'failed' && (
                                <p className="mt-3 text-[15px] text-crit md:text-[13px]">{proposal.error ? `No se pudo enviar: ${proposal.error}` : 'No se pudo enviar.'}</p>
                            )}
                            {!open && proposal.status !== 'failed' && statusLine[proposal.status] && (
                                <p className="mt-3 text-[15px] text-muted md:text-[13px]">{statusLine[proposal.status]}</p>
                            )}
                            {error && <p className="mt-3 text-[15px] text-crit md:text-[13px]">{error}</p>}
                        </>
                    ) : props.question ? (
                        <ol className="list-none p-0">
                            <PlanStep n={1} kind="Necesito saber">
                                <p className="text-[16px] leading-[1.5] md:text-[14px]">{props.question.text}</p>
                                <p className="mt-2 text-[15px] text-muted md:text-[12px]">Dímelo y lo preparo.</p>
                            </PlanStep>
                        </ol>
                    ) : (
                        <p className="text-[16px] leading-[1.5] text-muted md:text-[14px]">Todavía no hay una respuesta preparada. Dime cómo quieres responder y la preparo para que la apruebes.</p>
                    )}
                </section>
            </div>

            {proposal && (open || proposal.status === 'failed' || (proposal.undo && proposal.status === 'approved')) && (
                <AlertCtaBar>
                    {open &&
                        (editing ? (
                            <Button variant="primary" size="large" disabled={busy || text.trim() === ''} onClick={() => void decide('edit', { status: 'edited', text })}>
                                Guardar el texto
                            </Button>
                        ) : (
                            <>
                                <Button variant="primary" size="large" disabled={busy} onClick={() => void decide('approve', { status: 'approved', undo: true })}>
                                    Aprobar y enviar
                                </Button>
                                <Button variant="secondary" size="large" disabled={busy} onClick={() => setEditing(true)}>
                                    Cambiar el texto
                                </Button>
                            </>
                        ))}
                    {open && (
                        <Button variant="tertiary" size="large" destructive disabled={busy} onClick={() => void decide('reject', { status: 'rejected' })}>
                            No enviar
                        </Button>
                    )}
                    {!open && proposal.status === 'failed' && (
                        <Button variant="primary" size="large" disabled={busy} onClick={() => void decide('approve', { status: 'approved', undo: true, error: null })}>
                            Reintentar el envío
                        </Button>
                    )}
                    {proposal.undo && proposal.status === 'approved' && (
                        <Button variant="secondary" size="large" disabled={busy} onClick={() => void decide('undo', { status: 'undone', undo: false })}>
                            Deshacer el envío
                        </Button>
                    )}
                </AlertCtaBar>
            )}
        </CanvasFrame>
    );
};

export default CommsReview;
