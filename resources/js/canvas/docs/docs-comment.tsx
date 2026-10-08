import ActionBar from '@/actions/action-bar';
import CanvasFrame from '@/canvas/canvas-frame';
import type { DocumentCommentProps } from '@/canvas/docs/types';
import type { CanvasComponent } from '@/canvas/types';

/**
 * A proposed comment, signed by the person, as a draft bubble (as in the prototype).
 */
const DocsComment: CanvasComponent<DocumentCommentProps> = ({ index, ...props }) => (
    <CanvasFrame index={index} title={props.action.label} source="Docs">
        {props.quote && (
            <blockquote className="mb-3 border-l-2 border-hl pl-3 text-[13.5px] text-muted italic">«{props.quote}»</blockquote>
        )}

        <div className="rounded-[10px] border border-dashed border-accent bg-accent-soft px-3.5 py-2.5">
            <small className="mb-1 block text-[11px] text-accent">Comentario de {props.author} · borrador</small>
            <p className="text-[14px] whitespace-pre-wrap">{props.comment}</p>
        </div>

        <ActionBar initial={props.action} doneLabel="Comentario publicado" />
    </CanvasFrame>
);

export default DocsComment;
