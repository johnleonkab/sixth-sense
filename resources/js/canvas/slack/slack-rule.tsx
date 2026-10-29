import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

type SlackRuleProps = {
    title: string;
    body: string;
    saved: boolean;
    action: ActionState | null;
};

/**
 * A stated attention rule. A tap waits for "sí"; a cold-start answer is already saved.
 */
const SlackRule: CanvasComponent<SlackRuleProps> = ({ index, title, body, saved, action }) => {
    return (
        <CanvasFrame index={index} title={title} source="Slack">
            <p className="text-[15px] leading-[1.5] whitespace-pre-wrap">{body}</p>
            {action ? (
                <ActionBar initial={action} doneLabel="Regla guardada" verb="guardar" />
            ) : (
                saved && <p className="mt-3 text-[12.5px] text-muted">Queda guardado, por encima de lo aprendido.</p>
            )}
        </CanvasFrame>
    );
};

export default SlackRule;
