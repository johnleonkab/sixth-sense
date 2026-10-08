import ActionBar from '@/actions/action-bar';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { DocumentEditProps } from '@/canvas/docs/types';
import type { CanvasComponent } from '@/canvas/types';

/**
 * A proposed edit as a draft: what goes away struck through, what comes in
 * highlighted, with some of the surrounding text.
 */
const DocsEdit: CanvasComponent<DocumentEditProps> = ({ index, ...props }) => {
    const status = useCanvas().actions.actions[props.action.id]?.status ?? props.action.status;
    const count = props.changes.length === 1 ? 'un cambio' : `${props.changes.length} cambios`;

    return (
        <CanvasFrame index={index} title={props.action.label} source={`${props.source ?? 'Docs'} · ${props.title}`}>
            <p className="-mt-1 mb-4 text-[12.5px] text-muted">
                {status === 'executed' ? `${count[0].toUpperCase()}${count.slice(1)} aplicado` : `Borrador de ${count} · aún no se ha tocado nada`}
            </p>

            <ol className="flex flex-col gap-3">
                {props.changes.map((change, i) => (
                    <li key={i} className="rounded-xl border border-dashed border-line bg-bg px-4 py-3 text-[14px] leading-[1.65]">
                        <span className="text-muted">{change.before}</span>
                        {change.removed && <del className="rounded-[3px] bg-crit-soft px-0.5 text-crit">{change.removed}</del>}
                        {change.inserted && (
                            <ins className="rounded-[3px] bg-accent-soft px-0.5 whitespace-pre-wrap text-accent no-underline">{change.inserted}</ins>
                        )}
                        <span className="text-muted">{change.after}</span>
                    </li>
                ))}
            </ol>

            <ActionBar initial={props.action} doneLabel={props.source === 'Notion' ? 'Cambio aplicado en Notion' : 'Cambio aplicado en el documento'} />
        </CanvasFrame>
    );
};

export default DocsEdit;
