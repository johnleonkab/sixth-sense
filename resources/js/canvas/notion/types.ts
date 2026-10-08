import type { ActionState } from '@/actions/types';
import type { Block, DocumentCommentThread } from '@/canvas/docs/types';

export type NotionPageSummary = {
    number: number;
    ref: string;
    title: string;
    icon: string | null;
    url: string | null;
    workspace: string;
    edited_at: string | null;
    parent: string | null;
};

export type NotionResultsProps = { title: string; pages: NotionPageSummary[] };

export type NotionPageProps = {
    ref: string;
    title: string;
    icon: string | null;
    url: string | null;
    /** Only when the page is published to the web. */
    public_url: string | null;
    workspace: string;
    blocks: Block[];
    comments: DocumentCommentThread[];
};

export type NotionNewPageProps = { action: ActionState; title: string; parent: string; blocks: Block[] };
