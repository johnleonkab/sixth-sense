export type AlertState = 'judging' | 'important' | 'planning' | 'ready' | 'routine' | 'resolved' | 'dismissed' | 'meeting';

/** Mirrors InboxItem::toAlert(). */
export type Alert = {
    id: number;
    source: 'gmail' | 'slack' | 'calendar' | 'agenda' | 'jira';
    source_label: string;
    actor_name: string | null;
    /** Email subject; for Slack, where it was said ("Mensaje directo", "Mención en #producto"). */
    title: string;
    why: string;
    received_at: string;
    external_id: string;
    external_url: string | null;
    account: string | null;
    /** pending: waits for the person's answer; notice: a reminder or something that needs nothing. */
    status: 'pending' | 'resolved' | 'dismissed' | 'notice';
    /** Where it stands, as the widget shows it. Mirrors InboxItem::state(). */
    state: AlertState;
    resolution: string | null;
    resolved_at?: string | null;
    /** A conversation is one alert: what was said in it since the person last dealt with it, oldest first. */
    messages?: { id: string; from: string | null; text: string; at: string }[];
    /** How the reply engine proposes to resolve it, once it has thought it through. */
    proposal: { summary: string | null } | null;
    /** What the scheduler did about it, if it asks for work of the person's own. */
    task?: { id: number; status: string; status_label: string; title: string; starts_at: string | null } | null;
    /** Meetings only. */
    starts_at: string | null;
    meeting_url: string | null;
    event_ref: string | null;
};
