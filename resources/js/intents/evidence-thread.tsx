import { useState } from 'react';

import { ServiceIconFor } from '@/components/service-icon';
import type { Evidence } from '@/intents/types';

const timeFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

function when(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '' : timeFormat.format(date);
}

/** The quote, and the conversation across channels once it is asked for. */
export default function EvidenceThread({ evidence }: { evidence: Evidence }) {
    const [open, setOpen] = useState(false);
    const messages = evidence.message_count === 1 ? '1 mensaje' : `${evidence.message_count} mensajes`;
    const channels = evidence.channel_count === 1 ? '1 canal' : `${evidence.channel_count} canales`;

    return (
        <div className="mt-4">
            {evidence.quote && <p className="border-l-2 border-line pl-3 text-[14px] text-ink">{evidence.quote}</p>}
            {evidence.message_count > 0 && (
                <button type="button" className="mt-2 text-[13px] text-accent" aria-expanded={open} onClick={() => setOpen((current) => !current)}>
                    {messages} en {channels}
                </button>
            )}
            {open && (
                <ol className="mt-3 flex flex-col gap-3">
                    {evidence.conversation.map((message, index) => (
                        <li key={`${message.at ?? ''}-${index}`} className="flex gap-2">
                            <ServiceIconFor reference={message.source} size={16} />
                            <div className="min-w-0">
                                <p className="text-[12px] text-muted">
                                    {message.author ?? 'Alguien'}
                                    {when(message.at) && ` · ${when(message.at)}`}
                                </p>
                                <p className="text-[14px] text-ink">{message.text}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
