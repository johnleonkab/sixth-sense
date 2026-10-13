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

export type WingmanMeeting = WingmanMeetingSummary & {
    description: string | null;
    attendees: { name: string; email: string | null; response?: string | null }[];
    context: WingmanContext | null;
    context_prepared_at: string | null;
    paused: boolean;
    /** What was said, oldest first (the latest few hundred lines). */
    transcript: { id: number; speaker: string; is_me: boolean; text: string; at: string }[];
};
