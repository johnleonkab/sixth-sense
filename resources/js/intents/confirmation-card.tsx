import { useState } from 'react';

import { Button } from '@/components/button';
import UncertainSourceNotice from '@/intents/uncertain-source-notice';
import type { Confirmation } from '@/intents/types';

/** One of the three confirmation cards. One tap answers the whole card. */
export default function ConfirmationCard({
    card,
    onAnswer,
}: {
    card: Confirmation;
    onAnswer: (answer: string, extra?: { text?: string }) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState('');

    if (card.kind === 'approve') {
        return (
            <div className="flex flex-col gap-2 rounded-2xl bg-soft px-3 py-3">
                {card.change && <p className="text-[13px] text-ink">{card.change}</p>}
                {card.notice && <UncertainSourceNotice notice={card.notice} />}
                {card.previews?.map((preview, index) =>
                    preview ? (
                        <p key={`${preview}-${index}`} className="text-[14px] text-ink">
                            {preview}
                        </p>
                    ) : null,
                )}
                <div className="flex flex-wrap items-center gap-2">
                    {card.answers
                        .filter((answer) => answer.id !== 'guide' && answer.id !== 'edit')
                        .map((answer) => (
                            <Button key={answer.id} type="button" size="small" variant={answer.id === 'approve' ? 'primary' : 'secondary'} onClick={() => onAnswer(answer.id)}>
                                {answer.label}
                            </Button>
                        ))}
                    <Button type="button" size="small" onClick={() => setEditing((current) => !current)}>
                        Editar
                    </Button>
                    <span className="text-[12px] text-muted">Guiar: dilo en voz alta.</span>
                </div>
                {editing && (
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const next = text.trim();

                            if (next !== '') {
                                onAnswer('edit', { text: next });
                                setText('');
                                setEditing(false);
                            }
                        }}
                    >
                        <input
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            aria-label="Nuevo texto del borrador"
                            className="rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink"
                        />
                        <Button type="submit" size="small" variant="primary">
                            Guardar
                        </Button>
                    </form>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-2xl bg-soft px-3 py-3">
            {card.prompt && <p className="text-[14px] text-ink">{card.prompt}</p>}
            {card.current && <p className="text-[13px] text-muted">{card.current}</p>}
            <div className="flex flex-wrap items-center gap-2">
                {card.answers.map((answer) => (
                    <Button key={answer.id} type="button" size="small" variant={answer.id === 'confirm' || answer.id === 'done' ? 'primary' : 'secondary'} onClick={() => onAnswer(answer.id)}>
                        {answer.label}
                    </Button>
                ))}
            </div>
        </div>
    );
}
