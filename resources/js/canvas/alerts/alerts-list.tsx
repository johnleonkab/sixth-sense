import type { Alert } from '@/alerts/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { relativeDate } from '@/canvas/mail/types';
import type { CanvasComponent } from '@/canvas/types';
import { ServiceIconFor } from '@/components/service-icon';

type AlertsListProps = {
    title: string;
    alerts: (Alert & { number: number })[];
};

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/**
 * Alerts, numbered so the person can say "revisa el 2": who, why, and how
 * the reply engine proposes to resolve each.
 */
const AlertsList: CanvasComponent<AlertsListProps> = ({ index, title, alerts }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title={title} source="Avisos">
            <ol className="-mx-2.5">
                {alerts.map((alert) => (
                    <li key={alert.id} className="border-t border-line first:border-t-0">
                        <button
                            type="button"
                            onClick={() =>
                                alert.source === 'calendar'
                                    ? runTool('show_calendar', { start_date: (alert.starts_at ?? alert.received_at).slice(0, 10) })
                                    : runTool('review_alert', { alert_id: alert.id })
                            }
                            className="grid w-full grid-cols-[26px_minmax(0,1fr)] gap-3 rounded-[10px] px-2.5 py-3 text-left hover:bg-soft"
                        >
                            <span
                                className={`mt-px grid size-[22px] place-items-center rounded-full border font-mono text-[11px] ${alert.status === 'pending' ? 'border-accent bg-accent text-accent-ink' : 'border-line text-muted'}`}
                            >
                                {alert.number}
                            </span>
                            <span className="min-w-0">
                                <span className="flex items-center gap-2 text-[12px] text-muted">
                                    <ServiceIconFor reference={alert.source} size={14} />
                                    <span className="truncate">
                                        {alert.source_label} · <b className="font-medium text-ink">{alert.actor_name}</b> · {alert.title}
                                    </span>
                                    <time className="ml-auto flex-none font-mono text-[11px] text-faint">{relativeDate(alert.received_at, browserTimezone)}</time>
                                </span>
                                <span className="mt-1 block text-[13.5px]">{alert.why}</span>
                                {alert.proposal?.summary && <span className="mt-1 block text-[13px] text-accent">→ {alert.proposal.summary}</span>}
                                {alert.status !== 'pending' && alert.status !== 'notice' && (
                                    <span className="mt-1 block text-[12px] text-muted">{alert.status === 'resolved' ? 'Resuelta' : 'Descartada'}</span>
                                )}
                            </span>
                        </button>
                    </li>
                ))}
            </ol>
        </CanvasFrame>
    );
};

export default AlertsList;
