export type WingmanMeetingSummary = {
    id: number;
    title: string;
    starts_at: string;
    ends_at: string;
    meeting_url: string;
    meeting_code: string | null;
    account: string | null;
    organizer: string | null;
    attendee_count: number;
    status: 'scheduled' | 'live' | 'ended';
    status_label: string;
    /** Whether Wingman already gathered its context. */
    prepared: boolean;
};

export type WingmanContext = {
    documents: { title: string | null; url: string }[];
    issues: {
        ref: string;
        key: string;
        summary: string;
        type: string;
        status: string;
        status_category: string;
        assignee: string | null;
        url: string | null;
        blocked_by: string[];
    }[];
    /** linked: the event links to it; otherwise found by the meeting's topic. */
    pages: { ref: string; title: string; url: string | null; linked: boolean }[];
    emails: { ref: string; from: string | null; subject: string; date: string; snippet: string; url: string | null }[];
    /** Services that could not answer: jira, notion, gmail, calendar. */
    failed: string[];
};

/** Something Wingman brought in during a call. */
export type WingmanInsight = {
    id: number;
    kind: 'slot' | 'issue' | 'page' | 'message';
    title: string;
    body: string | null;
    why: string | null;
    links: { label: string; url: string }[];
    /** What it proposes to do; it is reviewed and confirmed before anything happens. */
    action: { label: string; preview: string | null } | null;
    importance: 'normal' | 'high';
    feedback: 'up' | 'down' | null;
    created_at: string | null;
    dismissed?: boolean;
};

/** The living notes of a call. In commitments, who is "Tú" for the person. */
export type WingmanNotes = {
    summary: string;
    topics: { title: string; points: string[] }[];
    decisions: string[];
    questions: string[];
    commitments: { who: string; what: string; when: string | null }[];
};

export type WingmanMeeting = WingmanMeetingSummary & {
    description: string | null;
    attendees: { name: string; email: string | null; response?: string | null }[];
    context: WingmanContext | null;
    context_prepared_at: string | null;
    paused: boolean;
    notes: WingmanNotes | null;
    notes_updated_at: string | null;
    insights: WingmanInsight[];
    /** What was said, oldest first (the latest few hundred lines). */
    transcript: { id: number; speaker: string; is_me: boolean; text: string; at: string }[];
};
