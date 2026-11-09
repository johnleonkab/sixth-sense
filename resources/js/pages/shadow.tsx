import { router } from '@inertiajs/react';
import { useState } from 'react';

import { buttonClass } from '@/components/button';
import SettingsLayout from '@/layouts/settings-layout';

type Proposal = {
    id: number;
    status: string;
    text: string;
    deny_reason: string | null;
    outcome: string | null;
    undo: boolean;
};

type Item = {
    id: number;
    kind: string;
    urgency: string;
    text: string | null;
    lines: string[];
    feedback: string | null;
    seen: boolean;
    question_id: number | null;
    draft: string | null;
    proposal: Proposal | null;
};

const urgencyLabel: Record<string, string> = {
    now: 'Ahora',
    digest: 'Resumen',
    batch: 'Lote',
};

const marks = ['useful', 'not_urgent', 'not_relevant', 'wrong'] as const;

const feedbackLabel: Record<(typeof marks)[number], string> = {
    useful: 'Útil',
    not_urgent: 'No urgente',
    not_relevant: 'No es relevante',
    wrong: 'Mal',
};

function mark(id: number, feedback: 'useful' | 'not_urgent' | 'not_relevant' | 'wrong') {
    router.post(`/shadow/${id}/feedback`, { feedback });
}

function DraftField({ questionId, draft }: { questionId: number; draft: string }) {
    const [text, setText] = useState(draft);

    return (
        <form
            className="mt-3 space-y-2"
            onSubmit={(event) => {
                event.preventDefault();
                router.post(`/shadow/questions/${questionId}/draft`, { draft: text });
            }}
        >
            <label className="block text-[13px] text-muted" htmlFor={`draft-${questionId}`}>
                Borrador
            </label>
            <textarea
                id={`draft-${questionId}`}
                value={text}
                onChange={(event) => setText(event.target.value)}
                rows={3}
                className="w-full rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink outline-none focus:border-accent"
            />
            <div className="flex gap-2">
                <button type="submit" className={buttonClass({ size: 'small' })}>
                    Guardar
                </button>
                <button type="button" className={buttonClass({ variant: 'primary', size: 'small' })} onClick={() => router.post(`/shadow/questions/${questionId}/send`)}>
                    Proponer envío
                </button>
            </div>
        </form>
    );
}

function ProposalActions({ proposal }: { proposal: Proposal }) {
    const [text, setText] = useState(proposal.text);
    const open = proposal.status === 'pending' || proposal.status === 'edited';

    return (
        <div className="mt-3 space-y-2">
            {proposal.deny_reason && <p className="text-[13px] text-crit">No se envía: {proposal.deny_reason}</p>}
            {open && (
                <>
                    <textarea
                        aria-label="Texto de la propuesta"
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        rows={3}
                        className="w-full rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink outline-none focus:border-accent"
                    />
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className={buttonClass({ variant: 'primary', size: 'small' })} onClick={() => router.post(`/comms/proposals/${proposal.id}/approve`)}>
                            Aprobar
                        </button>
                        <button type="button" className={buttonClass({ size: 'small' })} onClick={() => router.post(`/comms/proposals/${proposal.id}/edit`, { text })}>
                            Cambiar texto
                        </button>
                        <button type="button" className={buttonClass({ size: 'small', destructive: true })} onClick={() => router.post(`/comms/proposals/${proposal.id}/reject`)}>
                            Rechazar
                        </button>
                    </div>
                </>
            )}
            {proposal.undo && (
                <button type="button" className={buttonClass({ size: 'small' })} onClick={() => router.post(`/comms/proposals/${proposal.id}/undo`)}>
                    Deshacer
                </button>
            )}
            {proposal.status === 'executed' && proposal.outcome !== 'deny' && <p className="text-[13px] text-muted">Ya se envió.</p>}
        </div>
    );
}

export default function Shadow({ items }: { items: Item[] }) {
    return (
        <SettingsLayout title="Sombra" description="Lo que habría dicho. No suena y no avisa.">
            {items.length === 0 ? (
                <p className="text-[15px] text-muted">Nada que decir.</p>
            ) : (
                <ul className="divide-y divide-line">
                    {items.map((item) => (
                        <li key={item.id} className="py-4">
                            <p className="text-[12px] tracking-wide text-faint uppercase">
                                {urgencyLabel[item.urgency] ?? item.urgency}
                                {item.seen ? ' · visto' : ''}
                            </p>
                            <p className="mt-1 text-[15px] text-ink">{item.text ?? item.kind}</p>
                            {item.lines.length > 0 && (
                                <ul className="mt-2 space-y-1 text-[14px] text-muted">
                                    {item.lines.map((line, index) => (
                                        <li key={`${item.id}-${index}`}>{line}</li>
                                    ))}
                                </ul>
                            )}
                            {item.question_id !== null && item.draft !== null && <DraftField questionId={item.question_id} draft={item.draft} />}
                            {item.proposal && <ProposalActions proposal={item.proposal} />}
                            <div className="mt-3 flex flex-wrap gap-2">
                                {marks.map((value) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className={buttonClass({ variant: item.feedback === value || (value === 'useful' && item.seen && item.feedback === null) ? 'primary' : 'secondary', size: 'small' })}
                                        onClick={() => mark(item.id, value)}
                                    >
                                        {feedbackLabel[value]}
                                    </button>
                                ))}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SettingsLayout>
    );
}
