import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import SlackMessage, { ClickableRow } from '@/canvas/slack/slack-message';
import type { SlackMessageData } from '@/canvas/slack/types';
import type { CanvasComponent } from '@/canvas/types';
import ServiceIcon from '@/components/service-icon';

type SlackUnreadProps = {
    title: string;
    items: { number: number; reason: 'dm' | 'mention'; count: number; message: SlackMessageData }[];
};

/**
 * What is waiting in Slack: direct messages and mentions, numbered.
 */
const SlackUnread: CanvasComponent<SlackUnreadProps> = ({ index, ...props }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title={props.title} source="Slack">
            {props.items.length === 0 ? (
                <p className="text-[13px] text-muted">No tienes mensajes directos ni menciones sin leer.</p>
            ) : (
                <ol className="-mx-2.5">
                    {props.items.map((item) => (
                        <li key={item.message.ref} className="border-t border-line first:border-t-0">
                            <ClickableRow
                                onOpen={() => runTool('show_slack_conversation', { message_id: item.message.ref })}
                                className="grid w-full grid-cols-[26px_minmax(0,1fr)] gap-3 rounded-[10px] px-2.5 py-3 text-left hover:bg-soft"
                            >
                                <span className="mt-0.5 grid size-[22px] place-items-center rounded-full border border-accent bg-accent font-mono text-[11px] text-accent-ink">
                                    {item.number}
                                </span>
                                <div className="min-w-0">
                                    <p className="mb-1 flex items-center gap-1.5 text-[12px] text-muted">
                                        <ServiceIcon service="slack" size={13} label={false} />
                                        {item.reason === 'dm'
                                            ? `Mensaje directo${item.count > 1 ? ` · ${item.count} sin leer` : ''}`
                                            : `Te mencionan en ${item.message.conversation}`}
                                    </p>
                                    <SlackMessage message={item.message} inThread />
                                </div>
                            </ClickableRow>
                        </li>
                    ))}
                </ol>
            )}
        </CanvasFrame>
    );
};

export default SlackUnread;
