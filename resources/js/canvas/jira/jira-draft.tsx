import ActionBar from '@/actions/action-bar';
import CanvasFrame from '@/canvas/canvas-frame';
import { IssueKey } from '@/canvas/jira/jira-bits';
import type { JiraDraftProps } from '@/canvas/jira/types';
import type { CanvasComponent } from '@/canvas/types';

const doneLabel: Record<JiraDraftProps['kind'], string> = {
    create: 'Issue creado en Jira',
    update: 'Cambio aplicado en Jira',
    link: 'Dependencia marcada',
    unlink: 'Dependencia quitada',
    comment: 'Comentario publicado',
};

/**
 * A change to Jira as a draft, until the person says "sí": a new issue as it
 * will look, the fields that change (before and after), a dependency, or a
 * comment.
 */
const JiraDraft: CanvasComponent<JiraDraftProps> = ({ index, ...props }) => (
    <CanvasFrame index={index} title={props.action.label} source="Jira · borrador">
        {props.kind === 'create' && props.issue && (
            <div className="rounded-xl border border-dashed border-accent bg-accent-soft/40 px-5 py-4">
                <p className="text-[12px] text-muted">
                    {props.issue.type} en <span className="font-mono">{props.issue.projectKey}</span>
                    {props.issue.parentKey && <> · dentro de <span className="font-mono text-jira">{props.issue.parentKey}</span></>}
                    {props.issue.priority && <> · prioridad {props.issue.priority}</>}
                    {props.issue.assignee && <> · para {props.issue.assignee}</>}
                </p>
                <h3 className="mt-1 text-[18px] font-medium tracking-[-0.01em]">{props.issue.summary}</h3>
                {props.issue.description && <p className="mt-2 max-h-[40vh] overflow-auto text-[13.5px] leading-[1.6] whitespace-pre-wrap">{props.issue.description}</p>}
                {(props.issue.components ?? []).length > 0 && <p className="mt-2 text-[12px] text-muted">Componentes: {props.issue.components?.join(', ')}</p>}
                {props.issue.labels.length > 0 && <p className="mt-2 text-[12px] text-muted">Etiquetas: {props.issue.labels.join(', ')}</p>}
            </div>
        )}

        {props.kind === 'update' && props.target && (
            <>
                <p className="mb-3 text-[13px]">
                    <IssueKey issue={props.target} /> {props.target.summary}
                </p>
                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-[13px]">
                    {props.changes?.map((change) => (
                        <div key={change.label} className="contents">
                            <dt className="text-muted">{change.label}</dt>
                            <dd className="min-w-0 whitespace-pre-wrap">
                                {change.before && <span className="mr-1.5 text-faint line-through">{change.before}</span>}
                                <span className="font-medium">{change.after}</span>
                            </dd>
                        </div>
                    ))}
                </dl>
            </>
        )}

        {(props.kind === 'link' || props.kind === 'unlink') && props.dependency && (
            <p className={`rounded-xl border border-dashed px-4 py-3 text-[14px] ${props.kind === 'unlink' ? 'border-crit/40 bg-crit-soft line-through' : 'border-accent bg-accent-soft/40'}`}>
                <span className="font-mono text-jira">{props.dependency.from}</span> {props.dependency.from_summary && <span className="text-muted">{props.dependency.from_summary} </span>}
                <b className="font-medium">{props.dependency.relation}</b> <span className="font-mono text-jira">{props.dependency.to}</span>{' '}
                {props.dependency.to_summary && <span className="text-muted">{props.dependency.to_summary}</span>}
            </p>
        )}

        {props.kind === 'comment' && (
            <>
                {props.target && (
                    <p className="mb-3 text-[13px]">
                        <IssueKey issue={props.target} /> {props.target.summary}
                    </p>
                )}
                {props.in_reply_to && (
                    <div className="mb-2 rounded-[10px] border border-line px-3.5 py-2.5">
                        <small className="mb-1 block text-[11px] text-muted">{props.in_reply_to.author}</small>
                        <p className="text-[13.5px] whitespace-pre-wrap">{props.in_reply_to.text}</p>
                    </div>
                )}
                <div className={`rounded-[10px] border border-dashed border-accent bg-accent-soft px-3.5 py-2.5 ${props.in_reply_to ? 'ml-6' : ''}`}>
                    <small className="mb-1 block text-[11px] text-accent">
                        {props.in_reply_to ? 'Respuesta' : 'Comentario'} de {props.author} · borrador
                        {props.mentions && props.mentions.length > 0 && <> · menciona a {props.mentions.join(', ')}</>}
                    </small>
                    <p className="text-[14px] whitespace-pre-wrap">{props.comment}</p>
                </div>
            </>
        )}

        <ActionBar initial={props.action} doneLabel={doneLabel[props.kind]} linkLabel="Abrir en Jira" />
    </CanvasFrame>
);

export default JiraDraft;
