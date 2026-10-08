import type { ReactNode } from 'react';

import { relativeDate } from '@/canvas/mail/types';
import { useCanvas } from '@/canvas/canvas-context';
import { initials, type SlackMessageData } from '@/canvas/slack/types';

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

const escapeRegExp = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Links open in a new tab, whether the text shows their label or the URL itself. */
function Text({ text, links }: { text: string; links: SlackMessageData['links'] }) {
    const targets = new Map(links.map((link) => [link.label ?? link.url, link.url]));

    if (targets.size === 0) {
        return <Mentions text={text} />;
    }

    const parts = text.split(new RegExp(`(${[...targets.keys()].map(escapeRegExp).join('|')})`));

    return (
        <>
            {parts.map((part, i) =>
                targets.has(part) ? (
                    <a key={i} href={targets.get(part)} target="_blank" rel="noreferrer" className="text-accent underline underline-offset-2">
                        {part}
                    </a>
                ) : (
                    <Mentions key={i} text={part} />
                ),
            )}
        </>
    );
}

/** "@Marta" and "#canal" in the accent colour, as in the prototype. */
function Mentions({ text }: { text: string }) {
    const parts = text.split(/(@[\p{L}][\p{L}.\-_]*(?:\s[\p{Lu}][\p{L}]+)?|#[\w-]+)/u);

    return (
        <>
            {parts.map((part, i) =>
                i % 2 === 1 ? (
                    <span key={i} className="text-accent">
                        {part}
                    </span>
                ) : (
                    <span key={i}>{part}</span>
                ),
            )}
        </>
    );
}

/**
 * A list row that opens something, and may hold its own buttons and links
 * (a file, a thread): a <button> could not contain them.
 */
export function ClickableRow({ onOpen, className, children }: { onOpen: () => void; className: string; children: ReactNode }) {
    return (
        <div
            role="button"
            tabIndex={0}
            onClick={(event) => (event.target as HTMLElement).closest('a, button') === null && onOpen()}
            onKeyDown={(event) => {
                if (event.target === event.currentTarget && (event.key === 'Enter' || event.key === ' ')) {
                    event.preventDefault();
                    onOpen();
                }
            }}
            className={`cursor-pointer ${className}`}
        >
            {children}
        </div>
    );
}

/**
 * One message, like the prototype's Slack thread: avatar, name, time, text,
 * files to open and, if it starts a thread, its replies.
 */
export default function SlackMessage({
    message,
    focus = false,
    showConversation = false,
    inThread = false,
}: {
    message: SlackMessageData;
    focus?: boolean;
    showConversation?: boolean;
    inThread?: boolean;
}) {
    const { runTool } = useCanvas();

    return (
        <div className={`grid grid-cols-[28px_minmax(0,1fr)] gap-3 ${focus ? '-mx-2.5 rounded-[10px] bg-accent-soft px-2.5 py-2' : ''}`}>
            <span
                aria-hidden
                className={`grid size-7 place-items-center rounded-full text-[10.5px] font-medium ${message.is_mine ? 'bg-ink text-bg' : 'bg-soft text-muted'}`}
            >
                {initials(message.author)}
            </span>
            <div className="min-w-0">
                <p className="text-[13px] font-medium">
                    {message.author}
                    <time className="ml-2 font-mono text-[11px] font-normal text-faint">{relativeDate(message.sent_at, browserTimezone)}</time>
                    {showConversation && <span className="ml-2 text-[12px] font-normal text-muted">en {message.conversation}</span>}
                </p>
                <p className="mt-px text-[14px] leading-[1.5] break-words whitespace-pre-wrap">
                    <Text text={message.text} links={message.links ?? []} />
                </p>

                {message.files.length > 0 && (
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {message.files.map((file) => (
                            <button
                                key={file.ref}
                                type="button"
                                onClick={() => runTool('open_slack_file', { file_id: file.ref })}
                                className="flex items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 py-1 text-[12.5px] hover:bg-soft"
                            >
                                <span aria-hidden className="font-mono text-[10px] text-muted uppercase">
                                    {file.kind === 'other' ? file.name.split('.').pop()?.slice(0, 4) : file.kind}
                                </span>
                                <span className="max-w-[220px] truncate">{file.name}</span>
                            </button>
                        ))}
                    </div>
                )}

                {!inThread && message.reply_count > 0 && message.thread_ref && (
                    <button
                        type="button"
                        onClick={() => runTool('show_slack_conversation', { thread_id: message.thread_ref })}
                        className="mt-1 text-[12.5px] font-medium text-accent hover:underline"
                    >
                        {message.reply_count === 1 ? '1 respuesta' : `${message.reply_count} respuestas`}
                    </button>
                )}
            </div>
        </div>
    );
}
