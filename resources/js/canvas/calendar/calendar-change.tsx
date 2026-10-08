import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { dateOf, timeOf } from '@/canvas/calendar/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

type Person = { name: string | null; email: string | null; response?: string | null };

type EventCard = {
    title: string;
    start: string;
    end: string;
    all_day?: boolean;
    location?: string | null;
    organizer?: string | null;
    attendees: Person[];
    meeting_url?: string | null;
    calendar?: string;
    description?: string | null;
    video_call?: boolean;
};

type CalendarChangeProps = {
    action: ActionState;
    kind: 'invite' | 'summary' | 'create' | 'cancel' | 'respond';
    event: EventCard | null;
    new_people?: Person[];
    heading?: string;
    summary?: string;
    existing_description?: string | null;
    new_event?: EventCard;
    conflicts?: { title: string; start: string; end: string }[];
    notified?: number;
    response?: 'accepted' | 'declined' | 'tentative';
    response_label?: string;
    previous_response?: string | null;
    comment?: string | null;
};

const kindLabel: Record<CalendarChangeProps['kind'], string> = {
    invite: 'Invitación',
    summary: 'Resumen',
    create: 'Reunión nueva',
    cancel: 'Cancelación',
    respond: 'Respuesta',
};

const dayFormat = new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });

function when(event: Pick<EventCard, 'start' | 'end' | 'all_day'>): string {
    const day = dayFormat.format(new Date(`${dateOf(event.start)}T12:00:00Z`));
    const text = event.all_day ? `${day} · todo el día` : `${day} · ${timeOf(event.start)}–${timeOf(event.end)}`;

    return text.charAt(0).toUpperCase() + text.slice(1);
}

const responseDot: Record<string, string> = {
    accepted: 'bg-ok',
    declined: 'bg-crit',
    tentative: 'bg-warn',
};

const responseText: Record<string, string> = {
    accepted: 'Asistiré',
    declined: 'No asistiré',
    tentative: 'Quizá',
    needsAction: 'Sin responder',
};

function PersonChip({ person, added = false }: { person: Person; added?: boolean }) {
    return (
        <li
            className={`flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[12.5px] ${added ? 'border border-dashed border-accent bg-accent-soft text-accent' : 'bg-soft'}`}
            title={person.email ?? undefined}
        >
            {added ? <span aria-hidden>+</span> : <i aria-hidden className={`size-1.5 rounded-full ${responseDot[person.response ?? ''] ?? 'bg-faint'}`} />}
            {person.name || person.email}
        </li>
    );
}

function Card({ event, struck = false, highlighted = false, children }: { event: EventCard; struck?: boolean; highlighted?: boolean; children?: React.ReactNode }) {
    return (
        <div
            className={`rounded-xl border px-4 py-3.5 ${highlighted ? 'border-dashed border-accent bg-accent-soft/50' : struck ? 'border-crit/40 bg-crit-soft' : 'border-line bg-bg'}`}
        >
            <p className={`text-[15px] font-medium ${struck ? 'text-crit line-through' : ''}`}>{event.title}</p>
            <p className="mt-0.5 text-[13px] text-muted">{when(event)}</p>
            {(event.location || event.meeting_url || event.video_call || event.calendar) && (
                <p className="mt-0.5 text-[12.5px] text-muted">
                    {[event.calendar && `Calendario de ${event.calendar}`, event.location, (event.meeting_url || event.video_call) && 'Google Meet']
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            )}
            {event.attendees.length > 0 && (
                <ul aria-label="Invitados" className="mt-2.5 flex flex-wrap gap-1.5">
                    {event.attendees.map((person, i) => (
                        <PersonChip key={i} person={person} />
                    ))}
                </ul>
            )}
            {children}
        </div>
    );
}

/**
 * A proposed calendar change on its event: what changes is highlighted, and
 * nothing happens until the person says "sí".
 */
const CalendarChange: CanvasComponent<CalendarChangeProps> = ({ index, ...props }) => {
    const status = useCanvas().actions.actions[props.action.id]?.status ?? props.action.status;
    const done = status === 'executed';
    const verb = { invite: 'invitar', summary: 'añadir', create: 'convocar', cancel: 'cancelarla', respond: 'responder' }[props.kind];

    return (
        <CanvasFrame index={index} title={props.action.label} source={`Calendar · ${kindLabel[props.kind]}`}>
            <p className={`-mt-1 mb-3 text-[12.5px] ${done ? 'text-ok' : 'text-muted'}`}>{done ? 'Hecho en tu calendario' : 'Borrador · aún no se ha cambiado nada'}</p>

            {props.kind === 'create' && props.new_event && (
                <>
                    <Card event={props.new_event} highlighted>
                        {props.new_event.description && <p className="mt-2.5 text-[13px] whitespace-pre-wrap">{props.new_event.description}</p>}
                    </Card>
                    {props.conflicts && props.conflicts.length > 0 && (
                        <div className="mt-3 rounded-lg bg-warn-soft px-3 py-2 text-[12.5px] text-warn">
                            Choca con{' '}
                            {props.conflicts.map((conflict) => `«${conflict.title}» (${timeOf(conflict.start)}–${timeOf(conflict.end)})`).join(', ')}.
                        </div>
                    )}
                </>
            )}

            {props.event && props.kind === 'invite' && (
                <Card event={props.event}>
                    <ul aria-label="Nuevos invitados" className="mt-1.5 flex flex-wrap gap-1.5">
                        {props.new_people?.map((person, i) => <PersonChip key={i} person={person} added />)}
                    </ul>
                </Card>
            )}

            {props.event && props.kind === 'summary' && (
                <Card event={props.event}>
                    {props.existing_description && (
                        <p className="mt-2.5 line-clamp-3 text-[12.5px] whitespace-pre-wrap text-muted">{props.existing_description}</p>
                    )}
                    <div className="mt-2.5 rounded-[10px] border border-dashed border-accent bg-accent-soft px-3.5 py-2.5">
                        <small className="mb-1 block text-[11px] text-accent">— {props.heading} —</small>
                        <p className="text-[13.5px] leading-[1.6] whitespace-pre-wrap">{props.summary}</p>
                    </div>
                </Card>
            )}

            {props.event && props.kind === 'cancel' && (
                <Card event={props.event} struck>
                    <p className="mt-2.5 text-[12.5px] text-crit">
                        Se borrará de tu calendario
                        {props.notified ? ` y se avisará a ${props.notified === 1 ? '1 invitado' : `${props.notified} invitados`}` : ''}.
                    </p>
                </Card>
            )}

            {props.event && props.kind === 'respond' && (
                <Card event={props.event}>
                    <div className="mt-3 flex flex-wrap items-center gap-2 text-[13px]">
                        <span className="text-muted">Tu respuesta:</span>
                        {props.previous_response && props.previous_response !== props.response && (
                            <>
                                <span className="text-faint line-through">{responseText[props.previous_response] ?? props.previous_response}</span>
                                <span aria-hidden className="text-faint">→</span>
                            </>
                        )}
                        <span className="flex items-center gap-1.5 rounded-full border border-dashed border-accent bg-accent-soft px-3 py-0.5 font-medium text-accent">
                            <i aria-hidden className={`size-1.5 rounded-full ${responseDot[props.response ?? '']}`} />
                            {props.response_label}
                        </span>
                    </div>
                    {props.comment && <p className="mt-2 text-[13px] text-muted italic">«{props.comment}»</p>}
                </Card>
            )}

            <ActionBar
                initial={props.action}
                doneLabel={{ invite: 'Invitaciones enviadas', summary: 'Resumen añadido', create: 'Reunión convocada', cancel: 'Reunión cancelada', respond: 'Respuesta enviada' }[props.kind]}
                linkLabel="Abrir en Calendar"
                verb={verb}
                countdownLabel="Se hará"
            />
        </CanvasFrame>
    );
};

export default CalendarChange;
