import { useHttp, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/button';
import { echoConnection } from '@/lib/echo';
import { getJson } from '@/lib/http';
import IntentCard from '@/intents/intent-card';
import PossibleAskRow from '@/intents/possible-ask-row';
import TopicView from '@/intents/topic-view';
import type { Briefing, CardPayload, CollapsedCard, HomePayload, HomeRow, TopicPayload } from '@/intents/types';
import { topicLabel } from '@/intents/types';
import UncertainLinkRow from '@/intents/uncertain-link-row';
import type { SharedData } from '@/types';

export type FocusedDraft = {
    intentId: number;
    proposalIds: number[];
    show: (card: CardPayload) => void;
};

const groups = [
    { key: 'needs_you', title: 'Te toca' },
    { key: 'system_working', title: 'En marcha' },
    { key: 'waiting_on_others', title: 'Esperando a otros' },
    { key: 'done_today', title: 'Hecho hoy' },
] as const;

function pillFor(row: HomeRow, title: string): string {
    if (row.pending_proposals > 0) {
        return `Aprobar ${row.pending_proposals}`;
    }

    if (row.open_questions > 0) {
        return 'Tu decisión';
    }

    return title;
}

function collapsedFrom(row: HomeRow, title: string): CollapsedCard {
    return {
        id: row.id,
        what: row.what,
        topic: row.topic_subject,
        channels: [],
        who: '',
        deadline: row.deadline,
        pill: pillFor(row, title),
    };
}

/** The home by who has the ball. A live change reloads this board, and a card moves instead of appearing twice. */
export default function HomeBoard({
    initial,
    onFocus,
    onTell,
}: {
    initial: HomePayload;
    onFocus?: (focus: FocusedDraft | null) => void;
    onTell?: (text: string) => void;
}) {
    const { auth } = usePage<SharedData>().props;
    const [board, setBoard] = useState(initial);
    const [openId, setOpenId] = useState<number | null>(null);
    const [card, setCard] = useState<CardPayload | null>(null);
    const [topic, setTopic] = useState<TopicPayload | null>(null);
    const [briefing, setBriefing] = useState<Briefing | null>(null);
    const home = useHttp<Record<string, never>, HomePayload>({});
    const cards = useHttp<Record<string, never>, CardPayload>({});
    const topics = useHttp<Record<string, never>, TopicPayload>({});
    const actions = useHttp<{ importance?: number }>({});

    useEffect(() => {
        setBoard(initial);
    }, [initial]);

    useEffect(() => {
        void getJson<Briefing>('/comms/briefing')
            .then(setBriefing)
            .catch(() => setBriefing(null));
    }, [initial]);

    useEffect(() => {
        if (!onFocus) {
            return;
        }

        const approval = card?.open.confirmations?.find((item) => item.kind === 'approve');

        if (openId === null || card?.collapsed.id !== openId || !approval?.proposal_ids?.length) {
            onFocus(null);

            return;
        }

        onFocus({
            intentId: openId,
            proposalIds: approval.proposal_ids,
            show: (next) => setCard(next),
        });
    }, [card, openId, onFocus]);

    const refresh = () => {
        void home.get('/comms/home', {
            onSuccess: (payload) => {
                if (payload) {
                    setBoard(payload);
                }
            },
        });
    };

    useEffect(() => {
        const userId = auth.user?.id;

        if (!userId) {
            return;
        }

        const channel = echoConnection().private(`App.Models.User.${userId}`);
        channel.listen('.home.counts.changed', refresh);
        channel.listen('.intent.card.changed', refresh);

        return () => {
            channel.stopListening('.home.counts.changed', refresh);
            channel.stopListening('.intent.card.changed', refresh);
        };
    }, [auth.user?.id]);

    const targets = groups
        .flatMap((group) => board.lists[group.key])
        .concat(board.possible, board.stale)
        .map((row) => ({ id: row.id, what: row.what }));

    const openCard = (id: number) => {
        if (openId === id) {
            setOpenId(null);
            setCard(null);

            return;
        }

        setOpenId(id);
        setCard(null);
        void cards.get(`/comms/intents/${id}`, {
            onSuccess: (payload) => {
                if (payload?.collapsed.id === id) {
                    setCard(payload);
                }
            },
        });
    };

    const openTopic = (subject: string) => {
        setTopic(null);
        void topics.get(`/comms/topics/${encodeURIComponent(subject)}`, {
            onSuccess: (payload) => {
                if (payload?.subject === subject) {
                    setTopic(payload);
                }
            },
        });
    };

    const act = (id: number, path: string, importance?: number) => {
        if (importance !== undefined) {
            actions.setData('importance', importance);
            actions.transform(() => ({ importance }));
        }

        void actions.post(`/comms/intents/${id}/${path}`, {
            onSuccess: () => {
                setOpenId(null);
                setCard(null);
                refresh();
            },
        });
    };

    const replaceCard = (next: CardPayload) => {
        setCard(next);
        setTopic((current) =>
            current
                ? {
                      ...current,
                      intents: current.intents.map((item) => (item.collapsed.id === next.collapsed.id ? next : item)),
                  }
                : current,
        );
    };

    const shown = (row: HomeRow, title: string): CollapsedCard => (card?.collapsed.id === row.id ? card.collapsed : collapsedFrom(row, title));

    const morning = () => {
        if (!briefing || !onTell) {
            return;
        }

        const needs = briefing.needs_you.map((row) => row.what).join('. ');
        const changes = briefing.changes
            .map((line) => line.quote)
            .filter((quote): quote is string => quote !== null && quote !== '')
            .join('. ');
        const sentence = [needs && `Te toca: ${needs}.`, changes && `Esta noche: ${changes}.`].filter(Boolean).join(' ');

        onTell(sentence === '' ? 'Nada nuevo esta mañana.' : sentence);
    };

    return (
        <div className="mx-auto flex w-full max-w-[760px] flex-col gap-8 pt-1 pb-8">
            {briefing && (briefing.needs_you.length > 0 || briefing.changes.length > 0) && (
                <section className="flex flex-col gap-2 rounded-2xl bg-soft px-4 py-3">
                    <header className="flex items-center justify-between gap-3">
                        <h2 className="text-[15px] font-medium text-ink">Esta mañana</h2>
                        {onTell && (
                            <Button type="button" size="small" onClick={morning}>
                                Léemelo
                            </Button>
                        )}
                    </header>
                    {briefing.needs_you.map((row) => (
                        <p key={row.id} className="text-[14px] text-ink">
                            Te toca: {row.what}
                        </p>
                    ))}
                    {briefing.changes.map((line) => (
                        <p key={line.id} className="text-[14px] text-ink">
                            {line.quote}
                        </p>
                    ))}
                </section>
            )}
            <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                {groups.map((group) => (
                    <div key={group.key} className="rounded-2xl bg-soft px-3 py-2">
                        <p className="text-[12px] text-muted">{group.title}</p>
                        <p className="font-mono text-[20px] text-ink tabular-nums">{board.counters[group.key]}</p>
                    </div>
                ))}
            </div>

            {topic ? (
                <TopicView
                    topic={topic}
                    openId={openId}
                    onOpen={openCard}
                    onBack={() => setTopic(null)}
                    onDone={(id) => act(id, 'done')}
                    onNotForMe={(id) => act(id, 'not-for-me')}
                    onStillOpen={(id) => act(id, 'still-open')}
                    onImportance={(id, importance) => act(id, 'importance', importance)}
                    onCard={replaceCard}
                    onRefresh={refresh}
                />
            ) : (
                <>
                    {groups.map((group) => (
                        <section key={group.key} className="flex flex-col gap-2">
                            <header className="flex items-baseline justify-between gap-3 px-1">
                                <h2 className="text-[15px] font-medium text-ink">{group.title}</h2>
                                <span className="font-mono text-[12px] text-muted tabular-nums">{board.lists[group.key].length}</span>
                            </header>
                            {board.lists[group.key].map((row) => (
                                <IntentCard
                                    key={row.id}
                                    collapsed={shown(row, group.title)}
                                    open={openId === row.id ? (card?.open ?? null) : null}
                                    loading={openId === row.id && card === null}
                                    onToggle={() => openCard(row.id)}
                                    onDone={() => act(row.id, 'done')}
                                    onNotForMe={() => act(row.id, 'not-for-me')}
                                    onStillOpen={() => act(row.id, 'still-open')}
                                    onImportance={(importance) => act(row.id, 'importance', importance)}
                                    onCard={replaceCard}
                                    onRefresh={refresh}
                                />
                            ))}
                        </section>
                    ))}

                    {board.possible_asks > 0 && (
                        <section className="flex flex-col gap-2">
                            <h2 className="px-1 text-[15px] font-medium text-ink">
                                {board.possible_asks === 1 ? '1 posible petición' : `${board.possible_asks} posibles peticiones`}
                            </h2>
                            {board.possible.map((row) => (
                                <PossibleAskRow key={row.id} row={row} onOpen={openCard} />
                            ))}
                        </section>
                    )}

                    {board.uncertain_links > 0 && (
                        <section className="flex flex-col gap-2">
                            <h2 className="px-1 text-[15px] font-medium text-ink">
                                {board.uncertain_links === 1 ? '1 enlace por confirmar' : `${board.uncertain_links} enlaces por confirmar`}
                            </h2>
                            {board.uncertain.map((link) => (
                                <UncertainLinkRow
                                    key={link.id}
                                    link={link}
                                    targets={targets}
                                    onOptimistic={() =>
                                        setBoard((current) => ({
                                            ...current,
                                            uncertain_links: Math.max(0, current.uncertain_links - 1),
                                            uncertain: current.uncertain.filter((row) => row.id !== link.id),
                                        }))
                                    }
                                    onRollback={() => setBoard(board)}
                                    onSettled={refresh}
                                />
                            ))}
                        </section>
                    )}

                    {board.stale.length > 0 && (
                        <section className="flex flex-col gap-2">
                            <h2 className="px-1 text-[15px] font-medium text-ink">Sin movimiento</h2>
                            {board.stale.map((row) => (
                                <p key={row.id} className="px-1 text-[14px] text-ink">
                                    {row.what}
                                </p>
                            ))}
                        </section>
                    )}

                    {board.topics.some((item) => item.subject) && (
                        <section className="flex flex-wrap gap-2">
                            {board.topics
                                .filter((item) => item.subject)
                                .map((item) => (
                                    <button key={item.subject} type="button" className="rounded-full bg-soft px-3 py-1 text-[13px] text-ink" onClick={() => item.subject && openTopic(item.subject)}>
                                        {topicLabel(item.subject)}
                                    </button>
                                ))}
                        </section>
                    )}
                </>
            )}
        </div>
    );
}
