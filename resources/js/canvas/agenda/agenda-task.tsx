import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { Button } from '@/components/button';

export type AgendaTaskCard = {
    id: number;
    title: string;
    status: 'assessing' | 'scheduled' | 'proposed' | 'conflict' | 'cancelled' | 'failed';
    status_label: string;
    kind: 'task' | 'meeting' | null;
    urgency: string | null;
    urgency_label: string | null;
    urgency_reason: string | null;
    duration_minutes: number | null;
    deadline: string | null;
    attendees: { name: string | null; email: string | null }[];
    starts_at: string | null;
    ends_at: string | null;
    event_url: string | null;
    pending_action_id: number | null;
    error: string | null;
};

const dayFormat = new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });
const timeFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

const statusStyle: Record<AgendaTaskCard['status'], string> = {
    assessing: 'alerts-shimmer text-muted',
    scheduled: 'bg-ok/10 text-ok',
    proposed: 'bg-accent-soft text-accent',
    conflict: 'bg-warn-soft text-warn',
    cancelled: 'bg-soft text-faint',
    failed: 'bg-crit-soft text-crit',
};

const urgencyStyle: Record<string, string> = {
    now: 'bg-crit-soft text-crit',
    today: 'bg-warn-soft text-warn',
    this_week: 'bg-accent-soft text-accent',
    next_week: 'bg-soft text-ink',
    whenever: 'bg-soft text-muted',
};

function when(task: AgendaTaskCard): string | null {
    if (!task.starts_at || !task.ends_at) {
        return null;
    }

    const day = dayFormat.format(new Date(task.starts_at));

    return `${day.charAt(0).toUpperCase()}${day.slice(1)} · ${timeFormat.format(new Date(task.starts_at))}–${timeFormat.format(new Date(task.ends_at))}`;
}

/**
 * The scheduler's answer about one task, inline: where it went (or why it
 * could not), how urgent it judged it and why, and how to undo it.
 */
export function AgendaTaskSummary({ task, compact = false }: { task: AgendaTaskCard; compact?: boolean }) {
    const { runTool } = useCanvas();
    const slot = when(task);

    return (
        <div className="space-y-2.5">
            <div className="flex flex-wrap items-center gap-2">
                <span className={`rounded-full px-2 py-[2px] text-[11px] font-medium ${statusStyle[task.status]}`}>{task.status_label}</span>
                {task.urgency_label && <span className={`rounded-full px-2 py-[2px] text-[11px] font-medium ${urgencyStyle[task.urgency ?? ''] ?? 'bg-soft'}`}>{task.urgency_label}</span>}
                {task.duration_minutes && <span className="text-[12px] text-muted">{task.duration_minutes} min</span>}
                {task.kind === 'meeting' && <span className="text-[12px] text-muted">· reunión</span>}
            </div>

            {slot && (
                <p className={`font-medium tracking-[-0.01em] ${compact ? 'text-[14px]' : 'text-[17px]'} ${task.status === 'cancelled' ? 'text-faint line-through' : ''}`}>{slot}</p>
            )}

            {task.status === 'assessing' && <p className="text-[13px] text-muted">Valorando la urgencia y buscando hueco…</p>}
            {task.status === 'conflict' && (
                <p className="text-[13px] text-warn">No hay hueco a tiempo{task.deadline ? ` (antes del ${task.deadline})` : ''}. Dime qué muevo.</p>
            )}
            {task.error && <p className="text-[13px] text-crit">{task.error}</p>}
            {task.urgency_reason && <p className="text-[13px] text-muted">{task.urgency_reason}</p>}

            {task.attendees.length > 0 && (
                <p className="text-[12.5px] text-muted">Con {task.attendees.map((person) => person.name ?? person.email).join(', ')}</p>
            )}

            {(task.status === 'scheduled' || task.event_url) && (
                <div className="flex items-center gap-3 text-[12.5px]">
                    {task.event_url && (
                        <a href={task.event_url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                            Abrir en Calendar
                        </a>
                    )}
                    {task.status === 'scheduled' && (
                        <Button size="small" onClick={() => runTool('cancel_scheduled_task', { task_id: task.id })} className="ml-auto">
                            Quitar del calendario
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * A task the scheduler found time for, as its own canvas.
 */
const AgendaTask: CanvasComponent<{ task: AgendaTaskCard; action: ActionState | null }> = ({ index, task, action }) => (
    <CanvasFrame index={index} title={task.title} source="Agendador">
        <AgendaTaskSummary task={task} />
        {action && task.status === 'proposed' && <ActionBar initial={action} doneLabel="Reunión convocada" verb="convocar" linkLabel="Abrir en Calendar" />}
    </CanvasFrame>
);

export default AgendaTask;
