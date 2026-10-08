import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import SlackMessage, { ClickableRow } from '@/canvas/slack/slack-message';
import type { SlackMessageData } from '@/canvas/slack/types';
import type { CanvasComponent } from '@/canvas/types';

type SlackResultsProps = {
    title: string;
    search: Record<string, string>;
    messages: (SlackMessageData & { number: number })[];
};

const labels: Record<string, (value: string) => string> = {
    text: (value) => `«${value}»`,
    from: (value) => `de ${value}`,
    in: (value) => `en ${value}`,
    after: (value) => `desde ${value}`,
    before: (value) => `hasta ${value}`,
};

/**
 * Messages found, numbered; opening one shows it in its context.
 */
const SlackResults: CanvasComponent<SlackResultsProps> = ({ index, ...props }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title={props.title} source="Slack">
            <ul aria-label="Búsqueda" className="-mt-1 mb-3 flex flex-wrap gap-1.5">
                {Object.entries(props.search).map(([key, value]) => (
                    <li key={key} className="rounded-full bg-soft px-2.5 py-0.5 text-[12px]">
                        {labels[key]?.(value) ?? value}
                    </li>
                ))}
            </ul>

            {props.messages.length === 0 ? (
                <p className="text-[13px] text-muted">No he encontrado mensajes.</p>
            ) : (
                <ol className="-mx-2.5">
                    {props.messages.map((message) => (
                        <li key={message.ref} className="border-t border-line first:border-t-0">
                            <ClickableRow
                                onOpen={() => runTool('show_slack_conversation', { message_id: message.ref })}
                                className="grid w-full grid-cols-[26px_minmax(0,1fr)] gap-3 rounded-[10px] px-2.5 py-3 text-left hover:bg-soft"
                            >
                                <span className="mt-0.5 grid size-[22px] place-items-center rounded-full border border-line font-mono text-[11px] text-muted">
                                    {message.number}
                                </span>
                                <SlackMessage message={message} showConversation inThread />
                            </ClickableRow>
                        </li>
                    ))}
                </ol>
            )}
        </CanvasFrame>
    );
};

export default SlackResults;
