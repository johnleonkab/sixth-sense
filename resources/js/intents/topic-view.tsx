import { Button } from '@/components/button';
import IntentCard from '@/intents/intent-card';
import type { TopicPayload } from '@/intents/types';
import { topicLabel } from '@/intents/types';

/** One topic, with the cards that belong to it. */
export default function TopicView({
    topic,
    openId,
    onOpen,
    onBack,
    onDone,
    onNotForMe,
    onStillOpen,
    onImportance,
}: {
    topic: TopicPayload;
    openId: number | null;
    onOpen: (id: number) => void;
    onBack: () => void;
    onDone: (id: number) => void;
    onNotForMe: (id: number) => void;
    onStillOpen: (id: number) => void;
    onImportance: (id: number, importance: number) => void;
}) {
    return (
        <section className="flex flex-col gap-3">
            <header className="flex items-center justify-between gap-3">
                <h2 className="text-[15px] font-medium text-ink">{topicLabel(topic.subject)}</h2>
                <Button type="button" size="small" variant="tertiary" onClick={onBack}>
                    Volver
                </Button>
            </header>
            {topic.intents.map((card) => (
                <IntentCard
                    key={card.collapsed.id}
                    collapsed={card.collapsed}
                    open={openId === card.collapsed.id ? card.open : null}
                    onToggle={() => onOpen(card.collapsed.id)}
                    onDone={() => onDone(card.collapsed.id)}
                    onNotForMe={() => onNotForMe(card.collapsed.id)}
                    onStillOpen={() => onStillOpen(card.collapsed.id)}
                    onImportance={(importance) => onImportance(card.collapsed.id, importance)}
                />
            ))}
        </section>
    );
}
