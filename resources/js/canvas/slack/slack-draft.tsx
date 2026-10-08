import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import SlackMessage from '@/canvas/slack/slack-message';
import type { SlackMessageData } from '@/canvas/slack/types';
import type { CanvasComponent } from '@/canvas/types';

type SlackDraftProps = {
    action: ActionState;
    to: string;
    text: string;
    mentions: string[];
    thread: SlackMessageData | null;
};

/**
 * A Slack message waiting for the person's "sí", as in the prototype's draft bubble.
 */
const SlackDraft: CanvasComponent<SlackDraftProps> = ({ index, ...props }) => {
    const status = useCanvas().actions.actions[props.action.id]?.status ?? props.action.status;

    return (
        <CanvasFrame index={index} title={props.action.label} source="Slack">
            <p className={`-mt-1 mb-3 text-[12.5px] ${status === 'executed' ? 'text-ok' : 'text-muted'}`}>
                {status === 'executed' ? 'Enviado' : 'Borrador · aún no se ha enviado'}
            </p>

            {props.thread && (
                <div className="mb-3 opacity-70">
                    <SlackMessage message={props.thread} inThread />
                </div>
            )}

            <div className={props.thread ? 'ml-10' : ''}>
                <div className="rounded-[10px] border border-dashed border-accent bg-accent-soft px-3.5 py-2.5">
                    <small className="mb-1 block text-[11px] text-accent">
                        Tú, en {props.to}
                        {props.mentions.length > 0 && ` · etiquetando a ${props.mentions.join(', ')}`}
                    </small>
                    <p className="text-[14px] leading-[1.5] whitespace-pre-wrap">{props.text}</p>
                </div>
            </div>

            <ActionBar initial={props.action} doneLabel="Mensaje enviado" verb="enviar" />
        </CanvasFrame>
    );
};

export default SlackDraft;
