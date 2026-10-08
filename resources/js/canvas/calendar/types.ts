export type CalendarEventData = {
    id: string;
    title: string;
    /** ISO 8601, already in the person's timezone. */
    start: string;
    end: string;
    all_day: boolean;
    location: string | null;
    attendees: { name: string; email: string | null }[];
    account: string;
    calendar: string | null;
    calendar_color: string | null;
    declined: boolean;
    documents: { title: string | null; url: string }[];
    meeting_url: string | null;
    url: string | null;
};

export type CalendarCanvasProps = {
    title: string;
    from: string;
    to: string;
    timezone: string;
    filters: { title?: string; attendee?: string };
    accounts: string[];
    calendars: { account: string; name: string; color: string | null }[];
    failed_accounts: string[];
    events: CalendarEventData[];
};

/** "2026-10-07T10:30:00+02:00" → minutes since midnight, in the event's own offset. */
export const minutesOf = (iso: string): number => Number(iso.slice(11, 13)) * 60 + Number(iso.slice(14, 16));

export const dateOf = (iso: string): string => iso.slice(0, 10);

export const timeOf = (iso: string): string => iso.slice(11, 16);

const accountTones = ['border-accent', 'border-ok', 'border-warn', 'border-slack', 'border-gmail'];

export const showsSeveralCalendars = (props: CalendarCanvasProps): boolean => props.calendars.length > 1;

/**
 * Left border that tells calendars apart (in their Google colour) when there
 * are several, or accounts when the calendar has no colour.
 */
export function eventTone(props: CalendarCanvasProps, event: CalendarEventData): { className: string; style?: { borderColor: string } } {
    if (!showsSeveralCalendars(props) && props.accounts.length < 2) {
        return { className: 'border-transparent' };
    }

    if (event.calendar_color) {
        return { className: '', style: { borderColor: event.calendar_color } };
    }

    return { className: accountTones[Math.max(0, props.accounts.indexOf(event.account)) % accountTones.length] };
}

export function filterLabel(filters: CalendarCanvasProps['filters']): string | null {
    const parts = [filters.title && `«${filters.title}»`, filters.attendee && `con ${filters.attendee}`].filter(Boolean);

    return parts.length ? parts.join(' · ') : null;
}

export function sourceLabel(props: CalendarCanvasProps): string {
    if (showsSeveralCalendars(props)) {
        return `Calendar · ${props.calendars.length} calendarios`;
    }

    return props.accounts.length > 1 ? `Calendar · ${props.accounts.length} cuentas` : 'Calendar';
}
