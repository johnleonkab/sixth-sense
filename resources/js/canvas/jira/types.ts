import type { ActionState } from '@/actions/types';
import type { Block } from '@/canvas/docs/types';

export type JiraIssueSummary = {
    ref: string;
    key: string;
    summary: string;
    type: string;
    status: string;
    status_category: 'new' | 'indeterminate' | 'done' | string;
    priority: string | null;
    assignee: string | null;
    updated_at: string | null;
    url: string | null;
    site: string;
};

export type JiraComment = {
    number: number;
    ref: string;
    author: string;
    mine: boolean;
    text: string;
    blocks: Block[];
    created_at: string;
    mentions_me: boolean;
};

export type JiraLink = { id: string; ref: string; relation: string; key: string; summary: string; status: string };

export type JiraIssueProps = {
    issue: JiraIssueSummary;
    reporter: string | null;
    labels: string[];
    parent: { key: string; summary: string; type: string } | null;
    description: Block[];
    links: JiraLink[];
    subtasks: { key: string; summary: string; status: string }[];
    comments: JiraComment[];
};

export type JiraListProps = { title: string; jql: string | null; issues: (JiraIssueSummary & { number: number })[] };

export type JiraDraftProps = {
    action: ActionState;
    kind: 'create' | 'update' | 'link' | 'unlink' | 'comment';
    issue?: { projectKey: string; type: string; summary: string; description: string | null; parentKey: string | null; priority: string | null; labels: string[]; assignee: string | null };
    target?: JiraIssueSummary;
    changes?: { label: string; before: string | null; after: string }[];
    dependency?: { from: string; from_summary?: string; relation: string; to: string; to_summary?: string };
    comment?: string;
    author?: string;
    in_reply_to?: { author: string; text: string } | null;
    mentions?: string[];
};
