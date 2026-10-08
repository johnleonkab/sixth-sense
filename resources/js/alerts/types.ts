/** Mirrors InboxItem::toAlert(). */
export type Alert = {
    id: number;
    source: 'gmail' | 'slack' | 'calendar';
    source_label: string;
    actor_name: string | null;
    /** Email subject; for Slack, where it was said ("Mensaje directo", "Mención en #producto"). */
    title: string;
    why: string;
    received_at: string;
    external_id: string;
    external_url: string | null;
    account: string | null;
    /** pending: waits for the person's answer; notice: a reminder. */
    status: 'pending' | 'resolved' | 'dismissed' | 'notice';
    resolution: string | null;
    /** How the reply engine proposes to resolve it, once it has thought it through. */
    proposal: { summary: string | null } | null;
    /** Meetings only. */
    starts_at: string | null;
    meeting_url: string | null;
    event_ref: string | null;
};
