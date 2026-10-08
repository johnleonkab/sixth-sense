import CalendarNotes from '@/canvas/calendar/calendar-notes';
import {
    dateOf,
    eventTone,
    filterLabel,
    showsSeveralCalendars,
    sourceLabel,
    timeOf,
    type CalendarCanvasProps,
    type CalendarEventData,
} from '@/canvas/calendar/types';
import CanvasFrame from '@/canvas/canvas-frame';
import DocumentChip from '@/canvas/docs/document-chip';
import type { CanvasComponent } from '@/canvas/types';

const dayFormat = new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });

function dayHeading(date: string): string {
    const text = dayFormat.format(new Date(`${date}T12:00:00Z`));

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function groupByDay(events: CalendarEventData[]): [string, CalendarEventData[]][] {
    const days = new Map<string, CalendarEventData[]>();

    for (const event of events) {
        const day = dateOf(event.start);
        days.set(day, [...(days.get(day) ?? []), event]);
    }

    return [...days.entries()];
}

function details(event: CalendarEventData): string {
    const people = event.attendees.map((attendee) => attendee.name.split(' ')[0]);
    const who = people.length > 3 ? `${people.slice(0, 3).join(', ')} y ${people.length - 3} más` : people.join(', ');

    return [who, event.location].filter(Boolean).join(' · ');
}

/**
 * Events of several days, or the result of a search, as a list grouped by day.
 */
const CalendarAgenda: CanvasComponent<CalendarCanvasProps> = ({ index, ...props }) => {
    const filters = filterLabel(props.filters);
    // Say where each event comes from when there is more than one source.
    const origin = (event: CalendarEventData): string | null =>
        showsSeveralCalendars(props) ? event.calendar : props.accounts.length > 1 ? event.account : null;

    return (
        <CanvasFrame
            index={index}
            title={props.title}
            source={sourceLabel(props)}
            aside={filters && <span className="rounded-full bg-soft px-2 py-0.5 text-ink">{filters}</span>}
        >
            {props.events.length === 0 ? (
                <p className="text-[13px] text-muted">{filters ? 'Ningún evento coincide.' : 'No tienes nada en el calendario.'}</p>
            ) : (
                <div className="flex flex-col gap-5">
                    {groupByDay(props.events).map(([day, events]) => (
                        <section key={day}>
                            <h4 className="mb-1.5 text-[12px] font-medium text-muted">{dayHeading(day)}</h4>
                            <ul>
                                {events.map((event) => (
                                    <li
                                        key={event.id}
                                        className={`grid grid-cols-[86px_minmax(0,1fr)] gap-3 border-l-2 py-2 pl-2.5 ${eventTone(props, event).className}`}
                                        style={eventTone(props, event).style}
                                    >
                                        <span className="pt-px font-mono text-[11.5px] text-muted tabular-nums">
                                            {event.all_day ? 'Todo el día' : `${timeOf(event.start)}–${timeOf(event.end)}`}
                                        </span>
                                        <div className="min-w-0">
                                            <p className={`truncate text-[13.5px] ${event.declined ? 'text-faint line-through' : ''}`}>{event.title}</p>
                                            {(details(event) || origin(event)) && (
                                                <p className="truncate text-[12px] text-muted">
                                                    {details(event)}
                                                    {origin(event) && (
                                                        <span className="text-faint">
                                                            {details(event) && ' · '}
                                                            {origin(event)}
                                                        </span>
                                                    )}
                                                </p>
                                            )}
                                            {event.documents.length > 0 && (
                                                <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                    {event.documents.map((document) => (
                                                        <DocumentChip key={document.url} url={document.url} title={document.title} />
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}

            <CalendarNotes props={props} />
        </CanvasFrame>
    );
};

export default CalendarAgenda;
