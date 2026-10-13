import type { JiraIssueSummary } from '@/canvas/jira/types';

const categoryStyle: Record<string, string> = {
    new: 'bg-soft text-ink',
    indeterminate: 'bg-accent-soft text-accent',
    done: 'bg-ok/10 text-ok',
};

const priorityStyle: Record<string, string> = {
    highest: 'text-crit',
    high: 'text-warn',
    medium: 'text-muted',
    low: 'text-faint',
    lowest: 'text-faint',
};

export function StatusChip({ issue }: { issue: Pick<JiraIssueSummary, 'status' | 'status_category'> }) {
    return <span className={`rounded-[4px] px-1.5 py-[1px] text-[10.5px] font-semibold tracking-wide uppercase ${categoryStyle[issue.status_category] ?? 'bg-soft'}`}>{issue.status}</span>;
}

export function PriorityMark({ priority }: { priority: string | null }) {
    if (!priority) {
        return null;
    }

    const level = priority.toLowerCase();

    return (
        <span className={`inline-flex items-center gap-1 text-[12px] ${priorityStyle[level] ?? 'text-muted'}`}>
            <span aria-hidden>{level.includes('high') ? '▲' : level.includes('low') ? '▼' : '■'}</span>
            {priority}
        </span>
    );
}

export function IssueKey({ issue }: { issue: Pick<JiraIssueSummary, 'key' | 'url'> }) {
    return issue.url ? (
        <a href={issue.url} target="_blank" rel="noreferrer noopener" onClick={(event) => event.stopPropagation()} className="font-mono text-[12px] text-jira hover:underline">
            {issue.key}
        </a>
    ) : (
        <span className="font-mono text-[12px] text-jira">{issue.key}</span>
    );
}
