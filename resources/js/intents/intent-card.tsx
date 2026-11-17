import { ServiceIconFor } from '@/components/service-icon';
import { Button } from '@/components/button';
import EvidenceThread from '@/intents/evidence-thread';
import ActionMenu from '@/intents/action-menu';
import ConfirmationCard from '@/intents/confirmation-card';
import PlanChecklist from '@/intents/plan-checklist';
import type { CardPayload, CollapsedCard, Confirmation, OpenCard } from '@/intents/types';
import { topicLabel } from '@/intents/types';
import { postJson } from '@/lib/http';

const levels = [
    { score: 70, label: 'Alta' },
    { score: 40, label: 'Media' },
    { score: 10, label: 'Baja' },
] as const;

function names(people: string[], question: string | undefined): string {
    if (question) {
        return question;
    }

    return people.length > 0 ? people.join(', ') : '—';
}

/** Collapsed in a list, and open with what changed, the plan, and the evidence. */
export default function IntentCard({
    collapsed,
    open,
    loading = false,
    onToggle,
    onDone,
    onNotForMe,
    onStillOpen,
    onImportance,
    onCard,
    onRefresh,
}: {
    collapsed: CollapsedCard;
    open: OpenCard | null;
    loading?: boolean;
    onToggle: () => void;
    onDone: () => void;
    onNotForMe: () => void;
    onStillOpen: () => void;
    onImportance: (importance: number) => void;
    onCard?: (card: CardPayload) => void;
    onRefresh?: () => void;
}) {
    const deadline = open?.unclear.deadline ?? collapsed.deadline;
    const approval = open?.confirmations?.find((card) => card.kind === 'approve');

    const answer = (card: Confirmation, choice: string, extra?: { text?: string }) => {
        void postJson<{ card: CardPayload }>(`/comms/intents/${collapsed.id}/confirmations`, {
            kind: card.kind,
            answer: choice,
            proposal_ids: card.proposal_ids,
            link_id: card.link_id,
            field: card.field,
            text: extra?.text,
        }).then((payload) => onCard?.(payload.card));
    };

    return (
        <article className="rounded-2xl border border-line bg-surface px-4 py-3">
            <button type="button" onClick={onToggle} className="flex w-full items-start justify-between gap-3 text-left" aria-expanded={open !== null}>
                <span className="min-w-0">
                    <span className="flex items-center gap-2">
                        {collapsed.channels.map((channel) => (
                            <ServiceIconFor key={channel} reference={channel} size={14} label={false} />
                        ))}
                        <span className="text-[14px] text-ink">{collapsed.what}</span>
                    </span>
                    <span className="mt-1 block text-[12px] text-muted">
                        {topicLabel(collapsed.topic)}
                        {collapsed.who && ` · ${collapsed.who}`}
                        {deadline && ` · ${deadline}`}
                    </span>
                </span>
                <span className="rounded-full bg-soft px-2.5 py-0.5 text-[12px] text-muted">{collapsed.pill}</span>
            </button>
            {loading && !open && <div className="mt-3 h-4 animate-pulse rounded-full bg-soft" />}
            {open && (
                <div className="mt-4 border-t border-line pt-4">
                    {open.changed_since && <p className="text-[14px] text-ink">{open.changed_since}</p>}
                    <dl className="mt-3 grid gap-2 text-[13px]">
                        <div className="flex gap-2">
                            <dt className="text-muted">Quién pide</dt>
                            <dd className="text-ink">{names(open.requesters, open.unclear.requesters)}</dd>
                        </div>
                        <div className="flex gap-2">
                            <dt className="text-muted">Quién hace</dt>
                            <dd className="text-ink">{names(open.responders, open.unclear.responders)}</dd>
                        </div>
                        {open.unclear.kind && (
                            <div className="flex gap-2">
                                <dt className="text-muted">Tipo</dt>
                                <dd className="text-ink">{open.unclear.kind}</dd>
                            </div>
                        )}
                    </dl>
                    <div className="mt-4">
                        <h3 className="text-[13px] font-medium text-ink">Plan</h3>
                        <PlanChecklist plan={open.plan} />
                    </div>
                    {(open.confirmations ?? []).length > 0 && (
                        <div className="mt-4 flex flex-col gap-2">
                            {(open.confirmations ?? []).map((card, index) => (
                                <ConfirmationCard key={`${card.kind}-${index}`} card={card} onAnswer={(choice, extra) => answer(card, choice, extra)} />
                            ))}
                        </div>
                    )}
                    {(open.questions ?? []).map((question) => (
                        <div key={question.id} className="mt-4 flex flex-col gap-2">
                            <p className="text-[14px] text-ink">{question.text}</p>
                            {question.context && <p className="text-[13px] text-muted">{question.context}</p>}
                            <div className="flex flex-wrap gap-2">
                                {question.options.map((option) => (
                                    <Button
                                        key={option.id}
                                        type="button"
                                        size="small"
                                        onClick={() => {
                                            void postJson<{ card: CardPayload }>(`/comms/intents/${collapsed.id}/questions/${question.id}`, {
                                                option: option.id,
                                            }).then((payload) => onCard?.(payload.card));
                                        }}
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    ))}
                    <EvidenceThread evidence={open.evidence} />
                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <Button type="button" size="small" variant="primary" onClick={onDone}>
                            Hecho
                        </Button>
                        <Button type="button" size="small" onClick={onStillOpen}>
                            Sigue abierto
                        </Button>
                        <Button type="button" size="small" variant="tertiary" destructive onClick={onNotForMe}>
                            No es para mí
                        </Button>
                        {levels.map((level) => (
                            <Button key={level.score} type="button" size="small" variant="tertiary" onClick={() => onImportance(level.score)}>
                                {level.label}
                            </Button>
                        ))}
                    </div>
                    <div className="mt-3">
                        <ActionMenu
                            handoff={open.handoff ?? []}
                            canAlways={Boolean(approval?.action_class)}
                            canUndo={open.undo_proposal_id != null}
                            onNotForMe={onNotForMe}
                            onDelegate={(personId) => {
                                void postJson<{ card: CardPayload }>(`/comms/intents/${collapsed.id}/delegate`, { person_id: personId }).then((payload) => onCard?.(payload.card));
                            }}
                            onSnooze={(until) => {
                                void postJson<{ card: CardPayload }>(`/comms/intents/${collapsed.id}/snooze`, { until }).then((payload) => onCard?.(payload.card));
                            }}
                            onAlways={() => {
                                if (!approval?.action_class) {
                                    return;
                                }

                                void postJson(`/comms/intents/${collapsed.id}/always`, {
                                    action_class: approval.action_class,
                                    context: approval.context ?? {},
                                }).then(() => onRefresh?.());
                            }}
                            onUndo={() => {
                                if (open.undo_proposal_id == null) {
                                    return;
                                }

                                void postJson(`/comms/proposals/${open.undo_proposal_id}/undo`, {}).then(() => onRefresh?.());
                            }}
                        />
                    </div>
                </div>
            )}
        </article>
    );
}
