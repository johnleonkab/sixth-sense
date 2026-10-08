import ActionBar from '@/actions/action-bar';
import CanvasFrame from '@/canvas/canvas-frame';
import { Blocks } from '@/canvas/docs/docs-document';
import type { NotionNewPageProps } from '@/canvas/notion/types';
import type { CanvasComponent } from '@/canvas/types';

/**
 * A page about to be created, as it will look, until the person says "sí".
 */
const NotionNew: CanvasComponent<NotionNewPageProps> = ({ index, ...props }) => (
    <CanvasFrame index={index} title={props.action.label} source={`Notion · dentro de ${props.parent}`}>
        <div className="rounded-xl border border-dashed border-accent bg-accent-soft/40 px-6 py-5">
            <h2 className="mb-3 text-[24px] font-medium tracking-[-0.02em]">{props.title}</h2>
            <div className="text-[15px] leading-[1.7] break-words">
                {props.blocks.length > 0 ? <Blocks blocks={props.blocks} /> : <p className="text-muted">Página vacía.</p>}
            </div>
        </div>

        <ActionBar initial={props.action} doneLabel="Página creada en Notion" />
    </CanvasFrame>
);

export default NotionNew;
