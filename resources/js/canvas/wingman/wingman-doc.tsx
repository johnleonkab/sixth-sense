import { Link } from '@inertiajs/react';

import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { ServiceIconFor } from '@/components/service-icon';
import type { WingmanAction, WingmanNotes } from '@/wingman/types';

type WingmanDocProps = {
    meeting: { id: number; title: string; when: string; status: 'scheduled' | 'live' | 'ended'; closed: boolean };
    notes: WingmanNotes;
    actions: (WingmanAction & { number: number })[];
    /** The number of the one being confirmed, and its change. */
    highlight: number | null;
    action: ActionState | null;
};

const stateLabel: Record<WingmanAction['state'], string> = {
    proposed: '',
    reviewing: 'esperando tu «sí»',
    running: 'en marcha',
    done: 'hecho',
    failed: 'falló',
    dismissed: 'descartado',
};

const verbFor: Record<string, string> = { 'chat.send': 'enviar', 'mail.send': 'enviar', 'calendar.change': 'convocar', 'jira.create': 'crear', 'agenda.schedule': 'reservar' };

/**
 * A meeting Wingman followed, as the assistant shows it: what it was about,
 * what was decided, what is open, and what is left to do, numbered so it can
 * be done ("ejecuta la 2") or dropped by voice.
 */
const WingmanDoc: CanvasComponent<WingmanDocProps> = ({ index, meeting, notes, actions, highlight, action }) => (
    <CanvasFrame
        index={index}
        title={meeting.title}
        source={`Wingman · ${meeting.when}`}
        aside={
            <Link href={`/wingman/${meeting.id}`} className="text-[12.5px] text-accent hover:underline">
                Abrir entera ↗
            </Link>
        }
    >
        {notes.summary && <p className="text-[15px] leading-relaxed">{notes.summary}</p>}

        <div className="mt-4 grid gap-5 md:grid-cols-2">
            <div className="space-y-4 text-[14px]">
                {notes.decisions.length > 0 && (
                    <section>
                        <h3 className="mb-1 text-[12.5px] font-medium text-muted">Decisiones</h3>
                        <ul className="list-disc space-y-0.5 pl-5">
                            {notes.decisions.map((decision) => (
                                <li key={decision}>{decision}</li>
                            ))}
                        </ul>
                    </section>
                )}
                {notes.questions.length > 0 && (
                    <section>
                        <h3 className="mb-1 text-[12.5px] font-medium text-muted">Preguntas abiertas</h3>
                        <ul className="list-disc space-y-0.5 pl-5 text-warn">
                            {notes.questions.map((question) => (
                                <li key={question}>{question}</li>
                            ))}
                        </ul>
                    </section>
                )}
                {notes.decisions.length === 0 && notes.questions.length === 0 && !notes.summary && <p className="text-faint">Sin notas todavía.</p>}
            </div>

            <section>
                <h3 className="mb-1.5 text-[12.5px] font-medium text-muted">{meeting.closed ? 'Qué queda por hacer' : 'Preparado hasta ahora'}</h3>
                {actions.length === 0 ? (
                    <p className="text-[14px] text-faint">{meeting.status === 'ended' && !meeting.closed ? 'Wingman está cerrando la reunión…' : 'Nada pendiente.'}</p>
                ) : (
                    <ol className="space-y-1.5">
                        {actions.map((item) => (
                            <li key={item.id} className={`rounded-xl px-3 py-2 ${item.number === highlight ? 'border border-accent/50 bg-accent-soft/40' : 'bg-soft'}`}>
                                <div className="flex items-baseline gap-2 text-[14px]">
                                    <span className="grid size-5 flex-none place-items-center rounded-full border border-line font-mono text-[11px] text-muted">{item.number}</span>
                                    <ServiceIconFor reference={item.type} size={15} className="self-center" />
                                    <span className="min-w-0 flex-1 font-medium">{item.title}</span>
                                    {stateLabel[item.state] && <span className="flex-none text-[12px] text-muted">{stateLabel[item.state]}</span>}
                                </div>
                                {item.detail && <p className="mt-0.5 line-clamp-3 pl-7 text-[13px] whitespace-pre-line text-muted">{item.detail}</p>}
                                {item.number === highlight && action && (
                                    <ActionBar initial={action} verb={verbFor[item.type] ?? 'aplicar'} doneLabel="Hecho" linkLabel="Abrir" />
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </section>
        </div>
    </CanvasFrame>
);

export default WingmanDoc;
