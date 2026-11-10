import { useState } from 'react';

import AlertHeadline from '@/alerts/alert-headline';
import type { Alert } from '@/alerts/types';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { Button, Buttons } from '@/components/button';
import { HttpError, postJson } from '@/lib/http';

type Proposal = {
    id: number;
    status: string;
    text: string;
    deny_reason: string | null;
    undo: boolean;
};

type CommsReviewProps = {
    alert: Alert;
    source_label: string;
    what: string | null;
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

/**
 * One alert from the communications pipeline: what is asked and by whom on
 * the left, and on the right the proposal waiting for a yes. Nothing is
 * sent until the person approves it here.
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
        <CanvasFrame index={index} title="Aviso" source={props.source_label}>
            <div className="mb-4 rounded-[14px] border border-line bg-surface px-4 py-3">
                <AlertHeadline alert={props.alert} />
                {props.state === 'settled' && <p className="mt-1.5 pl-7 text-[12.5px] text-muted">Ya está resuelto.</p>}
            </div>

            <div className="grid gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                <section aria-label="Mensajes" className="min-w-0">
                    {props.messages.length === 0 ? (
                        <p className="text-[14px] text-muted">{props.alert.title}</p>
                    ) : (
                        <ol className="max-h-[40vh] space-y-3 overflow-auto">
                            {props.messages.map((message, position) => (
                                <li key={position}>
                                    <p className="text-[12.5px]">
                                        <b className="font-medium text-ink">{message.from}</b> <span className="font-mono text-[11px] text-faint">{message.when}</span>
                                    </p>
                                    <p className="text-[14px] leading-[1.55] break-words whitespace-pre-wrap">{message.text}</p>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                <section aria-label="Propuesta" className="grid min-w-0 content-start gap-2.5 rounded-2xl border border-line bg-surface px-4 py-3.5">
                    {proposal ? (
                        <>
                            <p className="text-[12px] text-muted">Respuesta propuesta</p>
                            {editing ? (
                                <textarea
                                    aria-label="Texto de la propuesta"
                                    value={text}
                                    onChange={(event) => setText(event.target.value)}
                                    rows={Math.min(10, Math.max(3, text.split('\n').length + 1))}
                                    className="w-full resize-y rounded-md border border-line bg-bg px-2.5 py-2 text-[13.5px] leading-[1.5] outline-none focus:border-accent"
                                />
                            ) : (
                                <p className="rounded-lg bg-soft px-3 py-2.5 text-[13.5px] leading-[1.5] whitespace-pre-wrap">{text}</p>
                            )}
                            {proposal.deny_reason && <p className="text-[13px] text-crit">No se envía: {proposal.deny_reason}</p>}
                            {open && (
                                <Buttons className="gap-x-3 gap-y-1.5">
                                    {editing ? (
                                        <Button variant="primary" disabled={busy || text.trim() === ''} onClick={() => void decide('edit', { status: 'edited', text })}>
                                            Guardar texto
                                        </Button>
                                    ) : (
                                        <>
                                            <Button variant="primary" disabled={busy} onClick={() => void decide('approve', { status: 'approved', undo: true })}>
                                                Aprobar y enviar
                                            </Button>
                                            <Button variant="secondary" disabled={busy} onClick={() => setEditing(true)}>
                                                Cambiar texto
                                            </Button>
                                        </>
                                    )}
                                    <Button variant="secondary" destructive disabled={busy} onClick={() => void decide('reject', { status: 'rejected' })}>
                                        Rechazar
                                    </Button>
                                </Buttons>
                            )}
                            {!open && statusLine[proposal.status] && <p className="text-[13px] text-muted">{statusLine[proposal.status]}</p>}
                            {proposal.undo && proposal.status === 'approved' && (
                                <Button variant="secondary" disabled={busy} onClick={() => void decide('undo', { status: 'undone', undo: false })}>
                                    Deshacer
                                </Button>
                            )}
                            {error && <p className="text-[13px] text-crit">{error}</p>}
                        </>
                    ) : props.question ? (
                        <>
                            <p className="text-[12px] text-muted">Necesito saber</p>
                            <p className="text-[14px] leading-[1.5]">{props.question.text}</p>
                            <p className="text-[12px] text-muted">Dímelo y lo preparo.</p>
                        </>
                    ) : (
                        <>
                            <p className="text-[14px] leading-[1.5]">Todavía no hay una respuesta preparada.</p>
                            <p className="text-[12px] text-muted">Dime cómo quieres responder y la preparo para que la apruebes.</p>
                        </>
                    )}
                </section>
            </div>
        </CanvasFrame>
    );
};

export default CommsReview;
