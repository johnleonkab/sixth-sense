import { useState } from 'react';

export type ThreadMessage = { from: string; when: string; text: string };

type AlertThreadProps = {
    messages: ThreadMessage[];
    /** Latest message, when it is kept apart from the thread (Slack, Gmail). */
    latestFrom?: string | null;
    latestText?: string | null;
    participants?: string[];
    source: string;
};

function Message({ from, when, text, featured = false }: ThreadMessage & { featured?: boolean }) {
    return (
        <li className={featured ? '' : 'border-l-2 border-line pl-3'}>
            <p className={`flex flex-wrap items-baseline gap-x-2 ${featured ? 'text-[15px]' : 'text-[14px]'} md:text-[13px]`}>
                {from && <b className="font-medium text-ink">{from}</b>}
                {when && <span className="font-mono text-[13px] text-faint md:text-[11px]">{when}</span>}
            </p>
            <p className={`mt-1 whitespace-pre-wrap ${featured ? 'text-[16px] leading-[1.5] md:text-[14.5px] md:leading-[1.55]' : 'text-[15px] leading-[1.45] text-muted md:text-[13px]'}`}>
                {text}
            </p>
        </li>
    );
}

/**
 * The conversation around an alert: Slack thread, Gmail thread or Jira
 * comments. Older messages stay behind a tap so the latest one is the one
 * that is read.
 */
export default function AlertThread({ messages, latestFrom, latestText, participants = [], source }: AlertThreadProps) {
    const [open, setOpen] = useState(false);
    const latest = latestText
        ? { from: latestFrom ?? '', when: '', text: latestText }
        : messages.length > 0
          ? messages[messages.length - 1]
          : null;
    const older = latestText ? messages : messages.slice(0, -1);
    const contextLabel = source === 'Jira' ? 'Comentario' : 'Conversación';

    if (!latest && older.length === 0) {
        return null;
    }

    return (
        <section aria-label={contextLabel} className="min-w-0">
            <h4 className="mb-3 text-[13px] font-medium tracking-wide text-muted uppercase">{contextLabel}</h4>
            {participants.length > 0 && <p className="mb-3 text-[14px] text-muted md:text-[12.5px]">Con {participants.join(', ')}</p>}
            {older.length > 0 && (
                <>
                    <button
                        type="button"
                        aria-expanded={open}
                        onClick={() => setOpen((current) => !current)}
                        className="mb-3 flex min-h-11 items-center text-[15px] font-medium text-accent md:min-h-0 md:text-[13px]"
                    >
                        {open ? 'Ocultar el hilo' : `Ver el hilo (${older.length})`}
                    </button>
                    {open && (
                        <ol className="mb-4 space-y-3">
                            {older.map((message, position) => (
                                <Message key={`${message.from}-${position}`} {...message} />
                            ))}
                        </ol>
                    )}
                </>
            )}
            {latest && (
                <ol>
                    <Message {...latest} featured />
                </ol>
            )}
        </section>
    );
}
