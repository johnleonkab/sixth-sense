import { router } from '@inertiajs/react';
import { useState } from 'react';

import { buttonClass } from '@/components/button';

export type IntentRow = {
    key: string;
    what: string;
    who: string;
    deadline: string | null;
    last_change: string | null;
    source_url: string | null;
    importance: 'alta' | 'media' | 'baja';
    uncertain: boolean;
    message: { source: string; external_id: string; text: string } | null;
};

export type IntentGroups = {
    waiting_on_me: IntentRow[];
    waiting_on_others: IntentRow[];
    quiet: IntentRow[];
};

const groups: { key: keyof IntentGroups; title: string }[] = [
    { key: 'waiting_on_me', title: 'Para mí' },
    { key: 'waiting_on_others', title: 'Espero algo de otros' },
    { key: 'quiet', title: 'Sin movimiento' },
];

const levels = [
    { key: 'baja', score: 10, label: 'Baja' },
    { key: 'media', score: 40, label: 'Media' },
    { key: 'alta', score: 70, label: 'Alta' },
] as const;

const day = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });

function correct(key: string, body: {
    action: string;
    importance?: number;
    target_key?: string;
    message_source?: string;
    message_external_id?: string;
    what?: string;
    messages?: { source?: string; external_id?: string }[];
}) {
    router.post(`/intents/${encodeURIComponent(key)}/corrections`, body, { preserveScroll: true });
}

function Row({ row, others }: { row: IntentRow; others: { key: string; what: string }[] }) {
    const [target, setTarget] = useState(others[0]?.key ?? '');
    const deadline = row.deadline ? day.format(new Date(`${row.deadline}T12:00:00`)) : null;

    return (
        <article className="border-t border-line px-1 py-4 first:border-t-0">
            <div className="flex items-baseline justify-between gap-4">
                <h3 className="text-[15px] font-medium tracking-[-0.01em]">{row.what}</h3>
                <span className="shrink-0 text-[12px] text-muted">{levels.find((level) => level.key === row.importance)?.label}</span>
            </div>
            <p className="mt-1 text-[13px] text-muted">
                {[row.who, deadline].filter(Boolean).join(' · ')}
            </p>
            {row.last_change && (
                <p className="mt-1 text-[13px]">
                    {row.source_url ? (
                        <a href={row.source_url} target="_blank" rel="noreferrer" className="underline decoration-line underline-offset-2 hover:decoration-ink">
                            {row.last_change}
                        </a>
                    ) : (
                        row.last_change
                    )}
                </p>
            )}

            {row.uncertain && (
                <div className="mt-3 rounded-xl bg-soft px-3 py-2.5">
                    <p className="text-[13px]">¿Es esto?</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        {others.length > 0 && row.message && (
                            <>
                                <select
                                    value={target}
                                    onChange={(event) => setTarget(event.target.value)}
                                    aria-label={`Mover «${row.what}»`}
                                    className="max-w-full rounded-full border border-line bg-surface px-3 py-1 text-[13px]"
                                >
                                    {others.map((other) => (
                                        <option key={other.key} value={other.key}>
                                            {other.what}
                                        </option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    className={buttonClass({ variant: 'secondary', size: 'small' })}
                                    onClick={() =>
                                        correct(row.key, {
                                            action: 'rematch',
                                            target_key: target,
                                            message_source: row.message?.source,
                                            message_external_id: row.message?.external_id,
                                        })
                                    }
                                >
                                    Mover
                                </button>
                            </>
                        )}
                        {row.message && (
                            <button
                                type="button"
                                className={buttonClass({ variant: 'secondary', size: 'small' })}
                                onClick={() =>
                                    correct(row.key, {
                                        action: 'split',
                                        what: row.message?.text,
                                        messages: [{ source: row.message?.source, external_id: row.message?.external_id }],
                                    })
                                }
                            >
                                Separar
                            </button>
                        )}
                    </div>
                </div>
            )}

            <div className="mt-3 flex flex-wrap gap-1.5">
                {levels.map((level) => (
                    <button
                        key={level.key}
                        type="button"
                        aria-pressed={row.importance === level.key}
                        className={buttonClass({ variant: row.importance === level.key ? 'primary' : 'tertiary', size: 'extra-small' })}
                        onClick={() => correct(row.key, { action: 'importance', importance: level.score })}
                    >
                        {level.label}
                    </button>
                ))}
                <button type="button" className={buttonClass({ variant: 'tertiary', size: 'extra-small', destructive: true })} onClick={() => correct(row.key, { action: 'close' })}>
                    Cerrar
                </button>
            </div>
        </article>
    );
}

export default function IntentBoard({ intents, only }: { intents: IntentGroups; only?: keyof IntentGroups }) {
    const shown = only ? groups.filter((group) => group.key === only) : groups;
    const all = shown.flatMap((group) => intents[group.key]);

    return (
        <div className="mx-auto w-full max-w-[760px] pb-8">
            {shown.map((group) => (
                <section key={group.key} aria-labelledby={`intents-${group.key}`} className="mb-8">
                    <h2 id={`intents-${group.key}`} className="text-[13px] font-medium text-muted">
                        {group.title}
                    </h2>
                    {intents[group.key].length === 0 ? (
                        <p className="mt-3 text-[14px] text-faint">Nada por aquí.</p>
                    ) : (
                        <div className="mt-2">
                            {intents[group.key].map((row) => (
                                <Row key={row.key} row={row} others={all.filter((other) => other.key !== row.key).map((other) => ({ key: other.key, what: other.what }))} />
                            ))}
                        </div>
                    )}
                </section>
            ))}
        </div>
    );
}
