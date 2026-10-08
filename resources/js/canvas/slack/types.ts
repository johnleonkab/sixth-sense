export type SlackFile = { ref: string; name: string; mime_type: string | null; size: number; kind: 'image' | 'video' | 'audio' | 'pdf' | 'other' };

export type SlackMessageData = {
    ref: string;
    conversation_ref: string;
    conversation: string;
    author: string;
    author_ref: string | null;
    text: string;
    /** ISO 8601, already in the person's timezone. */
    sent_at: string;
    thread_ref: string | null;
    reply_count: number;
    files: SlackFile[];
    mentions_me: boolean;
    is_mine: boolean;
    url: string | null;
    /** Web links; the text shows the label instead of the URL when there is one. */
    links: { url: string; label: string | null }[];
};

export type SlackConversationData = {
    ref: string;
    kind: 'channel' | 'private' | 'dm' | 'group';
    name: string;
    label: string;
    topic: string | null;
    workspace: string | null;
};

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}
