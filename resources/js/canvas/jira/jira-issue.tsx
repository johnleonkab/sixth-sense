import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { Blocks } from '@/canvas/docs/docs-document';
import { IssueKey, PriorityMark, StatusChip } from '@/canvas/jira/jira-bits';
import type { JiraIssueProps } from '@/canvas/jira/types';
import type { CanvasComponent } from '@/canvas/types';

const commentTime = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

/**
 * One issue: fields, description, dependencies and subtasks, and its
 * comments numbered beside it (those mentioning the person stand out).
 */
const JiraIssue: CanvasComponent<JiraIssueProps> = ({ index, ...props }) => {
    const { runTool } = useCanvas();
    const open = (key: string) => runTool('open_jira_issue', { issue: `${props.issue.ref.split(':')[0]}:${key}` });

    return (
        <CanvasFrame
            index={index}
            title={props.issue.summary}
            source={`Jira · ${props.issue.site}`}
            aside={
                props.issue.url && (
                    <a href={props.issue.url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                        Abrir en Jira ↗
                    </a>
                )
            }
        >
            <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[12.5px] text-muted">
                <IssueKey issue={props.issue} />
                <span>{props.issue.type}</span>
                <StatusChip issue={props.issue} />
                <PriorityMark priority={props.issue.priority} />
                {props.issue.assignee && <span>Asignado a {props.issue.assignee}</span>}
                {props.reporter && <span>· informa {props.reporter}</span>}
                {props.labels.map((label) => (
                    <span key={label} className="rounded-md bg-soft px-1.5 py-0.5 text-[11.5px]">
                        {label}
                    </span>
                ))}
            </div>

            <div className={props.comments.length > 0 ? 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]' : ''}>
                <div className="min-w-0">
                    {props.parent && (
                        <button type="button" onClick={() => open(props.parent!.key)} className="mb-3 text-left text-[12.5px] text-muted hover:text-ink">
                            {props.parent.type} <span className="font-mono text-jira">{props.parent.key}</span> · {props.parent.summary}
                        </button>
                    )}

                    <div className="text-[14.5px] leading-[1.65] break-words">
                        {props.description.length > 0 ? <Blocks blocks={props.description} /> : <p className="text-faint">Sin descripción.</p>}
                    </div>

                    {props.links.length > 0 && (
                        <section aria-label="Dependencias" className="mt-5">
                            <h4 className="mb-1.5 text-[12px] font-medium tracking-wide text-muted uppercase">Dependencias</h4>
                            <ul className="space-y-1">
                                {props.links.map((link) => (
                                    <li key={link.id}>
                                        <button type="button" onClick={() => open(link.key)} className="w-full rounded-lg px-2 py-1 text-left text-[13px] hover:bg-soft">
                                            <span className="text-muted">{link.relation}</span> <span className="font-mono text-jira">{link.key}</span> {link.summary}{' '}
                                            <span className="text-[11.5px] text-faint">({link.status})</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {props.subtasks.length > 0 && (
                        <section aria-label="Subtareas" className="mt-5">
                            <h4 className="mb-1.5 text-[12px] font-medium tracking-wide text-muted uppercase">Subtareas</h4>
                            <ul className="space-y-1">
                                {props.subtasks.map((subtask) => (
                                    <li key={subtask.key}>
                                        <button type="button" onClick={() => open(subtask.key)} className="w-full rounded-lg px-2 py-1 text-left text-[13px] hover:bg-soft">
                                            <span className="font-mono text-jira">{subtask.key}</span> {subtask.summary} <span className="text-[11.5px] text-faint">({subtask.status})</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>

                {props.comments.length > 0 && (
                    <aside aria-label="Comentarios" className="min-w-0 border-t border-line pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
                        <h4 className="mb-3 text-[12px] font-medium tracking-wide text-muted uppercase">Comentarios · {props.comments.length}</h4>
                        <ol className="max-h-[60vh] space-y-3 overflow-auto">
                            {props.comments.map((comment) => (
                                <li key={comment.ref} className={`rounded-xl border p-3 text-[13px] ${comment.mentions_me ? 'border-jira/40 bg-jira/5' : 'border-line'}`}>
                                    <div className="mb-1 flex items-center gap-2">
                                        <span className="grid size-[18px] flex-none place-items-center rounded-full border border-line font-mono text-[10px] text-muted">{comment.number}</span>
                                        <b className="truncate font-medium">{comment.mine ? 'Tú' : comment.author}</b>
                                        <time className="ml-auto flex-none font-mono text-[10.5px] text-faint">{commentTime.format(new Date(comment.created_at))}</time>
                                    </div>
                                    <div className="leading-[1.55] [&_p]:my-1">
                                        <Blocks blocks={comment.blocks} />
                                    </div>
                                    {comment.mentions_me && <p className="mt-1.5 text-[11.5px] text-jira">Te menciona</p>}
                                </li>
                            ))}
                        </ol>
                    </aside>
                )}
            </div>
        </CanvasFrame>
    );
};

export default JiraIssue;
