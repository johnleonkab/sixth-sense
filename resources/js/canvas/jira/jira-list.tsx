import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { IssueKey, PriorityMark, StatusChip } from '@/canvas/jira/jira-bits';
import type { JiraListProps } from '@/canvas/jira/types';
import type { CanvasComponent } from '@/canvas/types';

/**
 * Jira issues, numbered so the person can say "abre el 2".
 */
const JiraList: CanvasComponent<JiraListProps> = ({ index, title, jql, issues }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title={title} source="Jira">
            {jql && <p className="-mt-1 mb-3 truncate font-mono text-[11.5px] text-faint">{jql}</p>}
            <ol className="-mx-2.5">
                {issues.map((issue) => (
                    <li key={issue.ref} className="border-t border-line first:border-t-0">
                        <div
                            role="button"
                            tabIndex={0}
                            onClick={() => runTool('open_jira_issue', { issue: issue.ref })}
                            onKeyDown={(event) => event.key === 'Enter' && runTool('open_jira_issue', { issue: issue.ref })}
                            className="grid cursor-pointer grid-cols-[26px_minmax(0,1fr)_auto] items-center gap-3 rounded-[10px] px-2.5 py-2.5 hover:bg-soft"
                        >
                            <span className="grid size-[22px] place-items-center rounded-full border border-line font-mono text-[11px] text-muted">{issue.number}</span>
                            <span className="min-w-0">
                                <span className="flex items-center gap-2">
                                    <IssueKey issue={issue} />
                                    <span className="text-[11.5px] text-muted">{issue.type}</span>
                                </span>
                                <span className="block truncate text-[14px]">{issue.summary}</span>
                            </span>
                            <span className="flex flex-col items-end gap-1">
                                <StatusChip issue={issue} />
                                <span className="flex items-center gap-2 text-[11.5px] text-muted">
                                    <PriorityMark priority={issue.priority} />
                                    {issue.assignee && <span className="truncate">{issue.assignee}</span>}
                                </span>
                            </span>
                        </div>
                    </li>
                ))}
            </ol>
        </CanvasFrame>
    );
};

export default JiraList;
