import { useEffect, useState } from 'react';

import CalendarNotes from '@/canvas/calendar/calendar-notes';
import {
    dateOf,
    eventTone,
    minutesOf,
    showsSeveralCalendars,
    sourceLabel,
    timeOf,
    type CalendarCanvasProps,
    type CalendarEventData,
} from '@/canvas/calendar/types';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

const HOUR_HEIGHT = 44;
const MIN_EVENT_HEIGHT = 21;
const DAY_MINUTES = 24 * 60;

type PlacedEvent = {
    event: CalendarEventData;
    start: number;
    end: number;
    lane: number;
    lanes: number;
};

/** Minutes the event covers on this day, clamped to the day. */
function span(event: CalendarEventData, day: string): [number, number] {
    const start = dateOf(event.start) < day ? 0 : minutesOf(event.start);
    const end = dateOf(event.end) > day ? DAY_MINUTES : minutesOf(event.end);

    return [start, Math.max(end, start + 15)];
}

/** Side-by-side lanes for overlapping events. */
function place(events: CalendarEventData[], day: string): PlacedEvent[] {
    const sorted = events.map((event) => ({ event, span: span(event, day) })).sort((a, b) => a.span[0] - b.span[0]);
    const placed: PlacedEvent[] = [];
    let cluster: PlacedEvent[] = [];
    let clusterEnd = -1;

    const closeCluster = () => {
        const lanes = Math.max(1, ...cluster.map((item) => item.lane + 1));
        cluster.forEach((item) => (item.lanes = lanes));
        cluster = [];
    };

    for (const { event, span: [start, end] } of sorted) {
        if (start >= clusterEnd) {
            closeCluster();
        }

        const busy = new Set(cluster.filter((item) => item.end > start).map((item) => item.lane));
        let lane = 0;

        while (busy.has(lane)) {
            lane++;
        }

        const item = { event, start, end, lane, lanes: 1 };
        cluster.push(item);
        placed.push(item);
        clusterEnd = Math.max(clusterEnd, end);
    }

    closeCluster();

    return placed;
}

/** Current date and minute in the calendar's timezone. */
function useNow(timezone: string): { date: string; minutes: number } {
    const read = () => {
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat('en-CA', {
                timeZone: timezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            })
                .formatToParts(new Date())
                .map((part) => [part.type, part.value]),
        );

        return { date: `${parts.year}-${parts.month}-${parts.day}`, minutes: Number(parts.hour) * 60 + Number(parts.minute) };
    };

    const [now, setNow] = useState(read);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(read()), 30_000);

        return () => window.clearInterval(timer);
    }, [timezone]);

    return now;
}

/**
 * One day as a timeline, like the prototype's "Hoy" card.
 */
const CalendarDay: CanvasComponent<CalendarCanvasProps> = ({ index, ...props }) => {
    const now = useNow(props.timezone);
    const allDay = props.events.filter((event) => event.all_day);
    const timed = place(
        props.events.filter((event) => !event.all_day),
        props.from,
    );

    const firstHour = Math.min(9, ...timed.map((item) => Math.floor(item.start / 60)));
    const lastHour = Math.max(18, ...timed.map((item) => Math.ceil(item.end / 60)));
    const hours = Array.from({ length: lastHour - firstHour + 1 }, (_, i) => firstHour + i);
    const top = (minutes: number) => ((minutes - firstHour * 60) / 60) * HOUR_HEIGHT;
    const showsNow = now.date === props.from && now.minutes >= firstHour * 60 && now.minutes <= lastHour * 60;

    return (
        <CanvasFrame index={index} title={props.title} source={sourceLabel(props)}>
            {allDay.length > 0 && (
                <ul className="mb-4 flex flex-wrap gap-1.5">
                    {allDay.map((event) => (
                        <li key={event.id} className="rounded-md bg-soft px-2.5 py-1 text-[12.5px]">
                            {event.title}
                        </li>
                    ))}
                </ul>
            )}

            {props.events.length === 0 && <p className="mb-3 text-[13px] text-muted">No tienes nada en el calendario.</p>}

            <div className="relative ml-[46px]" style={{ height: (lastHour - firstHour) * HOUR_HEIGHT }}>
                {hours.map((hour) => (
                    <div
                        key={hour}
                        className="absolute -left-[46px] right-0 border-t border-line font-mono text-[10.5px] text-faint"
                        style={{ top: top(hour * 60) }}
                    >
                        <span className="relative -top-2 bg-surface pr-2">{String(hour).padStart(2, '0')}:00</span>
                    </div>
                ))}

                {timed.map(({ event, start, end, lane, lanes }) => {
                    const height = Math.max(top(end) - top(start) - 3, MIN_EVENT_HEIGHT);
                    const twoLines = height >= 36;
                    const tone = eventTone(props, event);

                    return (
                        <div
                            key={event.id}
                            title={`${timeOf(event.start)}–${timeOf(event.end)} · ${event.title}`}
                            className={`absolute flex overflow-hidden rounded-lg border-l-2 bg-soft px-2.5 text-[13px] leading-[1.25] ${twoLines ? 'flex-col py-1' : 'items-center gap-2'} ${tone.className} ${event.declined ? 'text-faint line-through' : ''}`}
                            style={{
                                ...tone.style,
                                top: top(start) + 1,
                                height,
                                left: `calc(6px + (100% - 6px) * ${lane / lanes})`,
                                width: `calc((100% - 6px) / ${lanes} - 4px)`,
                            }}
                        >
                            <span className="truncate">{event.title}</span>
                            <small className={`truncate text-[11.5px] text-muted ${twoLines ? 'mt-px' : 'flex-none'}`}>
                                {timeOf(event.start)}–{timeOf(event.end)}
                                {twoLines && event.location && ` · ${event.location}`}
                                {twoLines && showsSeveralCalendars(props) && event.calendar && ` · ${event.calendar}`}
                            </small>
                        </div>
                    );
                })}

                {showsNow && (
                    <div className="absolute -left-1 right-0 z-10 border-t-[1.5px] border-accent" style={{ top: top(now.minutes) }}>
                        <span className="absolute -top-1 left-0 size-[7px] rounded-full bg-accent" />
                    </div>
                )}
            </div>

            <CalendarNotes props={props} />
        </CanvasFrame>
    );
};

export default CalendarDay;
