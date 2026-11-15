import { router } from '@inertiajs/react';
import { useState } from 'react';

import { ServiceIconFor } from '@/components/service-icon';

export type ForMeItem = {
    id: number;
    index: number;
    source: string;
    source_label: string;
    actor_name: string | null;
    title: string;
    body: string;
    why: string;
    received_at: string;
    read: boolean;
    external_url: string | null;
};

export type ForMeInboxData = {
    pending_count: number;
    pending_label: string;
    filter: string;
    filters: { key: string; label: string; count: number }[];
    items: ForMeItem[];
};

const timeFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

function when(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : timeFormat.format(date);
}

/**
 * Para mí: the avisos inbox, list and detail, at most two columns.
 */
export default function ForMeInbox({ inbox }: { inbox: ForMeInboxData }) {
    const [openId, setOpenId] = useState<number | null>(inbox.items.find((item) => !item.read)?.id ?? inbox.items[0]?.id ?? null);
    const [readIds, setReadIds] = useState<Set<number>>(() => new Set(inbox.items.filter((item) => item.read).map((item) => item.id)));

    const shown = inbox.items;
    const open = shown.find((item) => item.id === openId) ?? shown[0] ?? null;
    const pending = shown.filter((item) => !readIds.has(item.id)).length;
    const pendingLabel = pending === 0 ? 'Al día' : pending === 1 ? '1 pendiente' : `${pending} pendientes`;

    const choose = (item: ForMeItem) => {
        setOpenId(item.id);

        if (readIds.has(item.id)) {
            return;
        }

        setReadIds((current) => new Set(current).add(item.id));
        router.post(`/para-mi/${item.id}/leido`, {}, { preserveScroll: true, preserveState: true, only: ['forMe'] });
    };

    return (
        <div className="mx-auto grid w-full max-w-[1120px] items-start gap-5 md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
            <section aria-labelledby="para-mi" className="min-w-0 rounded-2xl border border-line bg-surface">
                <header className="flex items-baseline justify-between gap-3 px-4 pt-4 pb-2">
                    <h2 id="para-mi" className="text-[15px] font-medium tracking-[-0.01em]">
                        Para mí
                    </h2>
                    <span className="font-mono text-[12px] text-muted tabular-nums">{pendingLabel}</span>
                </header>

                <div className="flex flex-wrap gap-1.5 px-4 pb-3">
                    {inbox.filters.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            aria-pressed={inbox.filter === option.key}
                            onClick={() =>
                                router.get('/', option.key === 'all' ? {} : { source: option.key }, {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['forMe'],
                                })
                            }
                            className={`rounded-full border px-2.5 py-1 text-[12.5px] ${inbox.filter === option.key ? 'border-accent bg-accent-soft text-ink' : 'border-line text-muted hover:bg-soft hover:text-ink'}`}
                        >
                            {option.label}
                            <span className="ml-1 tabular-nums text-faint">{option.count}</span>
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={() => {
                            setReadIds(new Set(inbox.items.map((item) => item.id)));
                            router.post('/para-mi/leidos', {}, { preserveScroll: true, preserveState: true, only: ['forMe'] });
                        }}
                        className="rounded-full px-2.5 py-1 text-[12.5px] text-muted hover:bg-soft hover:text-ink"
                    >
                        Marca todo como leído
                    </button>
                </div>

                <ul className="border-t border-line">
                    {shown.length === 0 ? (
                        <li className="px-4 py-8 text-center text-[14px] text-muted">Nada por aquí.</li>
                    ) : (
                        shown.map((item, index) => {
                            const unread = !readIds.has(item.id);

                            return (
                                <li key={item.id} className="border-b border-line last:border-b-0">
                                    <button
                                        type="button"
                                        aria-current={open?.id === item.id}
                                        onClick={() => choose(item)}
                                        className={`flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-soft ${open?.id === item.id ? 'bg-soft' : ''}`}
                                    >
                                        <span className="grid size-6 flex-none place-items-center rounded-md bg-soft font-mono text-[12px] text-muted tabular-nums">
                                            {index + 1}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-2">
                                                <ServiceIconFor reference={item.source} size={14} label={false} />
                                                <span className={`truncate text-[14px] ${unread ? 'font-medium text-ink' : 'text-muted'}`}>{item.title}</span>
                                            </span>
                                            <span className="mt-0.5 block truncate text-[13px] text-muted">{item.body}</span>
                                            <span className="mt-0.5 block truncate text-[12.5px] text-faint">{item.why}</span>
                                        </span>
                                        <time className="flex-none font-mono text-[12px] text-faint tabular-nums">{when(item.received_at)}</time>
                                    </button>
                                </li>
                            );
                        })
                    )}
                </ul>
            </section>

            <section aria-label="Detalle" className="min-w-0 rounded-2xl border border-line bg-surface">
                {open === null ? (
                    <p className="px-5 py-10 text-center text-[14px] text-muted">Elige un aviso.</p>
                ) : (
                    <article className="px-5 py-4">
                        <header className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="flex items-center gap-2 text-[12.5px] text-muted">
                                    <ServiceIconFor reference={open.source} size={14} label={false} />
                                    {open.source_label}
                                    {open.actor_name ? ` · ${open.actor_name}` : ''}
                                </p>
                                <h3 className="mt-1 text-[18px] font-medium tracking-[-0.02em]">{open.title}</h3>
                            </div>
                            <time className="font-mono text-[12px] text-faint tabular-nums">{when(open.received_at)}</time>
                        </header>
                        <p className="mt-3 rounded-xl bg-accent-soft px-3 py-2 text-[13px] text-ink">{open.why}</p>
                        <p className="mt-4 text-[15px] leading-relaxed whitespace-pre-wrap">{open.body}</p>
                        {open.external_url && (
                            <a href={open.external_url} target="_blank" rel="noreferrer" className="mt-4 inline-block text-[13px] text-muted underline underline-offset-2 hover:text-ink">
                                Abrir en {open.source_label}
                            </a>
                        )}
                    </article>
                )}
            </section>
        </div>
    );
}
