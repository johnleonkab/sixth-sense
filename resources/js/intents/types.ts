export type HomeRow = {
    id: number;
    key: string;
    what: string;
    importance: number;
    deadline: string | null;
    topic_subject: string | null;
    pending_proposals: number;
    open_questions: number;
    executed_actions: number;
    active_runs: number;
    waiting_watchers: number;
};

export type UncertainLink = {
    id: number;
    intent_id: number;
    what: string;
    alternative: string | null;
};

export type HomePayload = {
    counters: {
        needs_you: number;
        system_working: number;
        waiting_on_others: number;
        done_today: number;
    };
    lists: {
        needs_you: HomeRow[];
        system_working: HomeRow[];
        waiting_on_others: HomeRow[];
        done_today: HomeRow[];
    };
    possible_asks: number;
    possible: HomeRow[];
    uncertain_links: number;
    uncertain: UncertainLink[];
    stale: HomeRow[];
    topics: { subject: string | null; intent_ids: number[] }[];
};

export type CollapsedCard = {
    id: number;
    what: string;
    topic: string | null;
    channels: string[];
    who: string;
    deadline: string | null;
    pill: string;
};

export type Evidence = {
    quote: string | null;
    message_count: number;
    channel_count: number;
    conversation: {
        source: string;
        channel: string | null;
        at: string | null;
        author: string | null;
        text: string | null;
    }[];
};

export type OpenCard = {
    what: string;
    requesters: string[];
    responders: string[];
    topic: string | null;
    deadline: string | null;
    unclear: Record<string, string>;
    changed_since: string | null;
    plan: { id?: string; what?: string; after?: string[] }[];
    actions: { id: number; action: string; status: string }[];
    evidence: Evidence;
};

export type CardPayload = {
    collapsed: CollapsedCard;
    open: OpenCard;
};

export type TopicPayload = {
    subject: string;
    intents: CardPayload[];
};

export function topicLabel(subject: string | null): string {
    if (!subject) {
        return 'Sin tema';
    }

    const name = subject.startsWith('workstream:') ? subject.slice('workstream:'.length) : subject;

    return name.replaceAll('-', ' ');
}

export function intentTarget(alternative: string | null): number | null {
    if (!alternative?.startsWith('intent:')) {
        return null;
    }

    const id = Number(alternative.slice('intent:'.length));

    return Number.isInteger(id) && id > 0 ? id : null;
}
