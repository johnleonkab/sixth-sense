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

export type Run = { text: string; bold?: boolean; italic?: boolean; underline?: boolean; strike?: boolean; code?: boolean; link?: string };

export type Block =
    | { type: 'heading'; level: number; runs: Run[] }
    | { type: 'paragraph'; runs: Run[] }
    | { type: 'list_item'; level: number; ordered: boolean; checked?: boolean; runs: Run[] }
    | { type: 'quote'; runs: Run[] }
    | { type: 'code'; text: string }
    | { type: 'table'; rows: Block[][][] }
    | { type: 'rule' }
    | { type: 'image' }
    | { type: 'truncated' };

export type DocumentCommentReply = { id: string; author: string; mine: boolean; content: string; created_at: string };

/** An open comment thread, numbered as the assistant refers to it ("el comentario 2"). */
export type DocumentCommentThread = {
    number: number;
    id: string;
    author: string;
    mine: boolean;
    content: string;
    quote: string | null;
    created_at: string;
    awaits_user: boolean;
    replies: DocumentCommentReply[];
};

export type DocumentProps = {
    ref: string;
    title: string;
    url: string | null;
    account: string;
    blocks: Block[];
    comments?: DocumentCommentThread[];
};

export type DocumentChange = { type: string; before: string; removed: string; inserted: string; after: string };

export type DocumentEditProps = {
    action: ActionState;
    /** Where the document lives: Docs by default, or Notion. */
    source?: string;
    title: string;
    url: string | null;
    changes: DocumentChange[];
};

export type DocumentCommentProps = {
    action: ActionState;
    source?: string;
    title: string;
    url: string | null;
    quote: string | null;
    comment: string;
    author: string;
    /** When it answers a comment: its last message. */
    in_reply_to?: { author: string; content: string };
    resolve?: boolean;
};

export const personName = (person: Person): string => person?.name || person?.email || '—';
