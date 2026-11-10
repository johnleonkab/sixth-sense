import type { Alert } from '@/alerts/types';
import { ServiceIconFor } from '@/components/service-icon';

const timeFormat = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });

const taskTime = new Intl.DateTimeFormat('es-ES', { weekday: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

/** Where an alert stands, as a small label: what it is, and whether work is going on. */
export function StateChip({ state }: { state: Alert['state'] }) {
    const base = 'inline-flex flex-none items-center gap-1 rounded-full px-2 py-[2px] text-[10.5px] font-medium whitespace-nowrap';

    switch (state) {
        case 'judging':
            return (
                <span className={`${base} alerts-shimmer text-muted`}>
                    <span aria-hidden className="size-1.5 animate-pulse rounded-full bg-muted" />
                    Analizando
                </span>
            );
        case 'important':
            return <span className={`${base} bg-accent text-accent-ink`}>Importante</span>;
        case 'planning':
            return (
                <span className={`${base} border border-accent/40 bg-accent-soft text-accent`}>
                    <span aria-hidden className="size-2.5 animate-spin rounded-full border-[1.5px] border-accent border-t-transparent" />
                    Preparando respuesta
                </span>
            );
        case 'ready':
            return <span className={`${base} bg-accent-soft text-accent`}>✓ Propuesta lista</span>;
        case 'resolved':
            return <span className={`${base} bg-ok/10 text-ok`}>Resuelta</span>;
        case 'dismissed':
            return <span className={`${base} bg-soft text-faint`}>Descartada</span>;
        case 'meeting':
            return <span className={`${base} bg-soft text-ink`}>Reunión</span>;
        case 'question':
            return <span className={`${base} bg-accent-soft text-accent`}>Pregunta</span>;
        default:
            return <span className={`${base} bg-soft text-muted`}>Sin importancia</span>;
    }
}

/** " · 3 mensajes" when the conversation brought more than one since it was last dealt with. */
function messageCount(alert: Alert): string {
    const count = alert.messages?.length ?? 0;

    return count > 1 ? ` · ${count} mensajes` : '';
}

/**
 * An alert as it reads everywhere: the toast that pops up, its row in the
 * list and the top of the alert once opened. The system notification says
 * the same (AlertPush::forAlert): "who · where" as its title, then the
 * title and the why.
 */
export default function AlertHeadline({ alert, reserveDismiss = false }: { alert: Alert; reserveDismiss?: boolean }) {
    const addressed = alert.state === 'resolved' || alert.state === 'dismissed';

    return (
        <div className="grid w-full grid-cols-[auto_minmax(0,1fr)] gap-x-3 text-left">
            {addressed ? (
                <span aria-label="Atendida" className="mt-px grid w-4 place-items-center text-[12px] leading-none text-ok">
                    ✓
                </span>
            ) : (
                <ServiceIconFor reference={alert.source} size={16} className="mt-px" />
            )}
            <div className="min-w-0">
                <div className={`flex items-center gap-2 text-[12px] text-muted ${reserveDismiss ? 'pr-7' : ''}`}>
                    <span className="min-w-0 truncate">{alert.actor_name ? `${alert.actor_name} · ${alert.source_label}` : alert.source_label}</span>
                    <time className="ml-auto flex-none font-mono text-[11px]">{timeFormat.format(new Date(alert.received_at))}</time>
                    <StateChip state={alert.state} />
                </div>
                <b className="mt-0.5 line-clamp-2 block text-[13.5px] leading-[1.35] font-semibold">
                    {alert.title}
                    <span className="font-normal text-muted">{messageCount(alert)}</span>
                </b>
                <p className={`mt-px line-clamp-2 text-[13px] ${alert.state === 'judging' ? 'text-faint italic' : 'text-muted'}`}>
                    {alert.state === 'judging' ? 'Viendo si espera tu respuesta…' : alert.why}
                </p>
                {alert.proposal?.summary && alert.state === 'ready' && alert.proposal.summary !== alert.title && (
                    <p className="mt-0.5 line-clamp-1 text-[12px] text-accent">→ {alert.proposal.summary}</p>
                )}
                {alert.task && alert.task.status !== 'cancelled' && (
                    <p className="mt-0.5 line-clamp-1 text-[12px] text-ok">
                        ◷ {alert.task.status_label}
                        {alert.task.starts_at ? ` · ${taskTime.format(new Date(alert.task.starts_at))}` : ''}
                    </p>
                )}
            </div>
        </div>
    );
}
