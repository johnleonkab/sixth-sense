import CanvasFrame from '@/canvas/canvas-frame';
import SlackMessage from '@/canvas/slack/slack-message';
import type { SlackConversationData, SlackMessageData } from '@/canvas/slack/types';
import type { CanvasComponent } from '@/canvas/types';

type SlackConversationProps = {
    conversation: SlackConversationData;
    thread_ref: string | null;
    focus_ref: string | null;
    messages: SlackMessageData[];
};

/**
 * A channel or chat, a thread, or the moment around one message (highlighted).
 */
const SlackConversation: CanvasComponent<SlackConversationProps> = ({ index, ...props }) => (
    <CanvasFrame
        index={index}
        title={props.thread_ref ? `Hilo en ${props.conversation.label}` : props.conversation.label}
        source={props.conversation.workspace ? `Slack · ${props.conversation.workspace}` : 'Slack'}
    >
        {props.conversation.topic && !props.thread_ref && <p className="-mt-1 mb-3 text-[12.5px] text-muted">{props.conversation.topic}</p>}

        {props.messages.length === 0 ? (
            <p className="text-[13px] text-muted">No hay mensajes.</p>
        ) : (
            <div className="flex flex-col gap-4">
                {props.messages.map((message, i) => (
                    <div key={message.ref} className={props.thread_ref && i > 0 ? 'ml-10' : ''}>
                        <SlackMessage message={message} focus={message.ref === props.focus_ref} inThread={props.thread_ref !== null} />
                    </div>
                ))}
            </div>
        )}
    </CanvasFrame>
);

export default SlackConversation;
