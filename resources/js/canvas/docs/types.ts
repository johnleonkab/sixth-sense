import type { ActionState } from '@/actions/types';

export type Person = { name: string | null; email: string | null } | null;

export type DocumentResult = {
    number: number;
    ref: string;
    title: string;
    owner: Person;
    created_at: string;
    modified_at: string;
    modified_by: Person;
    account: string;
    url: string | null;
};

export type DocumentResultsProps = {
    title: string;
    search: Record<string, string>;
    accounts: string[];
    failed_accounts: string[];
    may_have_more: boolean;
    results: DocumentResult[];
};

export type Run = { text: string; bold?: boolean; italic?: boolean; underline?: boolean; strike?: boolean; link?: string };

export type Block =
    | { type: 'heading'; level: number; runs: Run[] }
    | { type: 'paragraph'; runs: Run[] }
    | { type: 'list_item'; level: number; ordered: boolean; runs: Run[] }
    | { type: 'table'; rows: Block[][][] }
    | { type: 'rule' }
    | { type: 'image' }
    | { type: 'truncated' };

export type DocumentProps = {
    ref: string;
    title: string;
    url: string | null;
    account: string;
    blocks: Block[];
};

export type DocumentChange = { type: string; before: string; removed: string; inserted: string; after: string };

export type DocumentEditProps = {
    action: ActionState;
    title: string;
    url: string | null;
    changes: DocumentChange[];
};

export type DocumentCommentProps = {
    action: ActionState;
    title: string;
    url: string | null;
    quote: string | null;
    comment: string;
    author: string;
};

export const personName = (person: Person): string => person?.name || person?.email || '—';
