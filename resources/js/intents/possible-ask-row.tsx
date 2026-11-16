import type { HomeRow } from '@/intents/types';
import { topicLabel } from '@/intents/types';

/** A possible ask sits under the line, outside the four lists. */
export default function PossibleAskRow({ row, onOpen }: { row: HomeRow; onOpen: (id: number) => void }) {
    return (
        <button type="button" onClick={() => onOpen(row.id)} className="flex w-full items-baseline justify-between gap-3 rounded-2xl border border-line bg-surface px-4 py-3 text-left">
            <span>
                <span className="block text-[14px] text-ink">{row.what}</span>
                <span className="text-[12px] text-muted">{topicLabel(row.topic_subject)}</span>
            </span>
            <span className="rounded-full bg-soft px-2.5 py-0.5 text-[12px] text-muted">Posible petición</span>
        </button>
    );
}
