import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { relativeDate } from '@/canvas/mail/types';
import SlackCorrections from '@/canvas/slack/slack-corrections';
import { ClickableRow } from '@/canvas/slack/slack-message';
import type { CanvasComponent } from '@/canvas/types';

type DigestItem = {
    number: number;
    id: number;
    ref: string;
    action: 'notice' | 'ignore';
    action_label: string;
    place_label: string;
    who: string;
    excerpt: string;
    reason: string;
    at: string;
};

type SlackDigestProps = {
    title: string;
    timezone: string;
    items: DigestItem[];
};

/**
 * What Slack left aside since yesterday: notice and ignore, numbered.
 */
const SlackDigest: CanvasComponent<SlackDigestProps> = ({ index, title, timezone, items }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title={title} source="Slack">
            {items.length === 0 ? (
                <p className="text-[13px] text-muted">Desde ayer no he dejado pasar ningún mensaje.</p>
            ) : (
                <ol className="-mx-2.5">
                    {items.map((item) => (
                        <li key={`${item.ref}-${item.number}`} className="border-t border-line first:border-t-0">
                            <ClickableRow
                                onOpen={() => runTool('show_slack_conversation', { message_id: item.ref })}
                                className="grid w-full grid-cols-[26px_minmax(0,1fr)] gap-3 rounded-[10px] px-2.5 py-3 text-left hover:bg-soft"
                            >
                                <span className="mt-0.5 grid size-[22px] place-items-center rounded-full border border-accent bg-accent font-mono text-[11px] text-accent-ink">
                                    {item.number}
                                </span>
                                <div className="min-w-0">
                                    <p className="mb-1 flex flex-wrap items-center gap-x-1.5 text-[12px] text-muted">
                                        <span>{item.who}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{item.place_label}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{item.action_label}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{relativeDate(item.at, timezone)}</span>
                                    </p>
                                    <p className="text-[14px] leading-snug">{item.excerpt}</p>
                                    <p className="mt-1 text-[12px] text-muted">{item.reason}</p>
                                    <SlackCorrections onCorrect={(correction) => runTool('correct_slack_triage', { decision_id: item.id, correction })} />
                                </div>
                            </ClickableRow>
                        </li>
                    ))}
                </ol>
            )}
        </CanvasFrame>
    );
};

export default SlackDigest;
