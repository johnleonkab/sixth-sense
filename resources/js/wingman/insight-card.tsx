import { useState } from 'react';

import ServiceIcon, { type Service, serviceOf } from '@/components/service-icon';
import { postJson } from '@/lib/http';
import type { WingmanInsight } from '@/wingman/types';

/** The integration a card is about: what it prepares, or what it found. */
const serviceFor = (insight: WingmanInsight): Service =>
    serviceOf(insight.action?.type) ?? ({ slot: 'calendar', issue: 'jira', page: 'notion', message: 'gmail' } as const)[insight.kind];

/**
 * One of Wingman's cards: what it brought in and why, its links, what it has
 * ready to do (reviewed later), and whether it helped.
 */
export default function InsightCard({ insight, compact = false, onDismiss }: { insight: WingmanInsight; compact?: boolean; onDismiss?: (id: number) => void }) {
    const [feedback, setFeedback] = useState(insight.feedback);

    const rate = (value: 'up' | 'down') => {
        const next = feedback === value ? null : value;
        setFeedback(next);
        void postJson(`/wingman/insights/${insight.id}/feedback`, { value: next }).catch(() => setFeedback(feedback));
    };

    const dismiss = () => {
        onDismiss?.(insight.id);
        void postJson(`/wingman/insights/${insight.id}/dismiss`, {}).catch(() => null);
    };

    return (
        <article className={`rounded-xl border bg-surface px-3 py-2.5 ${insight.importance === 'high' ? 'border-accent/40' : 'border-line'}`}>
            <header className="flex items-start gap-2">
                <ServiceIcon service={serviceFor(insight)} size={18} className="mt-px" />
                <h4 className="min-w-0 flex-1 text-[13.5px] leading-snug font-medium">{insight.title}</h4>
                {onDismiss && (
                    <button type="button" aria-label="Descartar" onClick={dismiss} className="-mt-0.5 -mr-1 flex-none rounded px-1 text-[13px] text-faint hover:text-ink">
                        ✕
                    </button>
                )}
            </header>

            {insight.body && <p className={`mt-1 text-[13px] whitespace-pre-line text-muted ${compact ? 'line-clamp-3' : ''}`}>{insight.body}</p>}

            {insight.action && (
                <p className="mt-1.5 rounded-lg bg-soft px-2.5 py-1.5 text-[12.5px]">
                    <span className="font-medium text-accent">Preparado:</span> {insight.action.label}
                    {!compact && insight.action.preview && <span className="block text-muted">{insight.action.preview}</span>}
                    <span className="block text-[11.5px] text-faint">No se hace nada sin tu «sí».</span>
                </p>
            )}

            <footer className="mt-1.5 flex items-center gap-2 text-[12px]">
                {insight.links.map((link) => (
                    <a key={link.url} href={link.url} target="_blank" rel="noreferrer noopener" className="font-medium text-accent hover:underline">
                        {link.label} ↗
                    </a>
                ))}
                {insight.why && <span className="min-w-0 flex-1 truncate text-faint">{insight.why}</span>}
                <span className="ml-auto flex flex-none gap-0.5">
                    {(['up', 'down'] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            aria-label={value === 'up' ? 'Me ha servido' : 'No me ha servido'}
                            aria-pressed={feedback === value}
                            onClick={() => rate(value)}
                            className={`rounded px-1 ${feedback === value ? 'bg-soft' : 'opacity-50 hover:opacity-100'}`}
                        >
                            {value === 'up' ? '👍' : '👎'}
                        </button>
                    ))}
                </span>
            </footer>
        </article>
    );
}
