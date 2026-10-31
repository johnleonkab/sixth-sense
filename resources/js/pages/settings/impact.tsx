import { router } from '@inertiajs/react';

import { ServiceIconFor } from '@/components/service-icon';
import SettingsLayout from '@/layouts/settings-layout';

type ImpactProps = {
    period: '7' | '30' | 'all';
    summary: { minutes: number; proposals: number; slots: number; events: number; by_service: { service: string; count: number }[] };
    weekly: { week: string; minutes: number }[];
    rates: { proposal: number; slot: number };
    alerts: {
        weeks: (AlertMeasure & { week: string; missed: number })[];
        classes: (AlertMeasure & { class: string })[];
    };
};

type AlertMeasure = {
    total: number;
    acceptance: number | null;
    edit_rate: number | null;
    edit_size: number | null;
    discards: Record<'not_important' | 'not_for_me' | 'bad_proposal' | 'none', number | null>;
    median_minutes: number | null;
    expired: number | null;
};

const classLabel: Record<string, string> = { reply: 'Respuesta', call: 'Llamada', task: 'Tarea', question: 'Pregunta' };

const percent = (value: number | null): string => (value === null ? '—' : `${Math.round(value * 100)} %`);

/** The pillars' metrics, one row each; one column per week or per class. */
const alertRows: { label: string; hint: string; value: (measure: AlertMeasure & { missed?: number }) => string }[] = [
    { label: 'Avisos', hint: 'Con un final: enviado, descartado, resuelto o caducado', value: (measure) => String(measure.total) },
    { label: 'Aceptación', hint: 'Enviados tal cual o modificados', value: (measure) => percent(measure.acceptance) },
    { label: 'Modificados', hint: 'De los enviados; entre paréntesis, caracteres cambiados de media', value: (measure) => `${percent(measure.edit_rate)}${measure.edit_size ? ` (${measure.edit_size})` : ''}` },
    { label: 'Descarte: no importa', hint: 'Falló el filtro de importancia', value: (measure) => percent(measure.discards.not_important) },
    { label: 'Descarte: no es para mí', hint: 'Falló a quién le toca', value: (measure) => percent(measure.discards.not_for_me) },
    { label: 'Descarte: propuesta mal', hint: 'Falló la clase o el borrador', value: (measure) => percent(measure.discards.bad_proposal) },
    { label: 'Hasta la acción', hint: 'Mediana desde el aviso', value: (measure) => (measure.median_minutes === null ? '—' : duration(measure.median_minutes)) },
    { label: 'Caducados', hint: 'Llegaron tarde o no hacían falta', value: (measure) => percent(measure.expired) },
    { label: 'Se escaparon', hint: 'En Slack, lo que hacía falta responder y no se avisó', value: (measure) => (measure.missed === undefined ? '' : String(measure.missed)) },
];

function AlertTable({ columns, heads }: { columns: (AlertMeasure & { missed?: number })[]; heads: string[] }) {
    return (
        <div className="overflow-x-auto rounded-2xl border border-line bg-surface">
            <table className="w-full min-w-[520px] text-[13px]">
                <thead>
                    <tr className="border-b border-line text-left text-[12px] text-muted">
                        <th className="px-4 py-2.5 font-normal">Métrica</th>
                        {heads.map((head) => (
                            <th key={head} className="px-3 py-2.5 text-right font-normal">
                                {head}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {alertRows
                        .filter((row) => row.label !== 'Se escaparon' || columns.some((column) => column.missed !== undefined))
                        .map((row) => (
                            <tr key={row.label} className="border-b border-line last:border-b-0">
                                <th scope="row" className="px-4 py-2 text-left font-normal" title={row.hint}>
                                    {row.label}
                                </th>
                                {columns.map((column, position) => (
                                    <td key={position} className="px-3 py-2 text-right font-mono tabular-nums">
                                        {row.value(column)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                </tbody>
            </table>
        </div>
    );
}

const periods: { key: ImpactProps['period']; label: string }[] = [
    { key: '7', label: '7 días' },
    { key: '30', label: '30 días' },
    { key: 'all', label: 'Desde el principio' },
];

const weekLabel = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });

function duration(minutes: number): string {
    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest ? `${hours} h ${rest} min` : `${hours} h`;
}

function Stat({ value, label, hint }: { value: number; label: string; hint: string }) {
    return (
        <div className="rounded-2xl border border-line bg-surface px-4 py-3.5">
            <p className="font-mono text-[26px] leading-none tracking-[-0.02em] tabular-nums">{value}</p>
            <p className="mt-1.5 text-[13.5px] font-medium">{label}</p>
            <p className="text-[12px] text-muted">{hint}</p>
        </div>
    );
}

export default function Impact({ period, summary, weekly, rates, alerts }: ImpactProps) {
    const choose = (key: ImpactProps['period']) => router.get('/settings/impact', key === '30' ? {} : { period: key }, { preserveScroll: true, preserveState: true, replace: true });
    const busiest = Math.max(1, ...weekly.map((week) => week.minutes));
    const topService = Math.max(1, ...summary.by_service.map((service) => service.count));
    const nothingYet = summary.proposals === 0 && summary.slots === 0 && summary.events === 0;

    return (
        <SettingsLayout title="Impacto" description="Lo que sixth-sense te ha ahorrado: lo que te prepara y tú solo confirmas, y lo que encuentra en tu calendario.">
            <div role="radiogroup" aria-label="Periodo" className="mb-6 inline-flex rounded-full border border-line bg-surface p-0.5 text-[13px]">
                {periods.map((option) => (
                    <button
                        key={option.key}
                        type="button"
                        role="radio"
                        aria-checked={period === option.key}
                        onClick={() => choose(option.key)}
                        className={`rounded-full px-3.5 py-1 transition-colors ${period === option.key ? 'bg-ink text-bg' : 'text-muted hover:text-ink'}`}
                    >
                        {option.label}
                    </button>
                ))}
            </div>

            <section className="rounded-2xl border border-line bg-surface px-5 py-5">
                <p className="text-[13px] text-muted">Tiempo ahorrado</p>
                <p className="mt-1 text-[40px] leading-none font-medium tracking-[-0.03em]">{duration(summary.minutes)}</p>
                {nothingYet && <p className="mt-2 text-[13px] text-faint">Todavía nada en este periodo: cada propuesta que confirmes y cada hueco que te busque sumarán aquí.</p>}

                <div className="mt-6" aria-label="Minutos ahorrados por semana">
                    <div className="flex h-[88px] items-end gap-2">
                        {weekly.map((week) => (
                            <div key={week.week} className="flex h-full flex-1 flex-col justify-end" title={`Semana del ${weekLabel.format(new Date(week.week))}: ${duration(week.minutes)}`}>
                                <div className={`w-full rounded-t-[4px] ${week.minutes ? 'bg-accent' : 'bg-soft'}`} style={{ height: `${Math.max(4, (week.minutes / busiest) * 100)}%` }} />
                            </div>
                        ))}
                    </div>
                    <div className="mt-1.5 flex gap-2 font-mono text-[10.5px] text-faint">
                        {weekly.map((week) => (
                            <span key={week.week} className="flex-1 text-center">
                                {weekLabel.format(new Date(week.week))}
                            </span>
                        ))}
                    </div>
                </div>
            </section>

            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                <Stat value={summary.proposals} label="Propuestas enviadas" hint={`Confirmadas con tu «sí» · ${rates.proposal} min cada una`} />
                <Stat value={summary.slots} label="Huecos encontrados" hint={`Tareas con hueco en tu calendario · ${rates.slot} min cada uno`} />
                <Stat value={summary.events} label="Eventos creados" hint="Reuniones convocadas y tiempo reservado" />
            </div>

            {summary.by_service.length > 0 && (
                <section className="mt-6">
                    <h2 className="mb-2 text-[13px] text-muted">Propuestas por servicio</h2>
                    <ul className="space-y-1.5">
                        {summary.by_service.map((service) => (
                            <li key={service.service} className="grid grid-cols-[100px_minmax(0,1fr)_40px] items-center gap-3 text-[13px]">
                                <span className="flex items-center gap-1.5">
                                    <ServiceIconFor reference={service.service} size={15} label={false} />
                                    {service.service}
                                </span>
                                <span className="h-2 overflow-hidden rounded-full bg-soft">
                                    <span className="block h-full rounded-full bg-accent" style={{ width: `${(service.count / topService) * 100}%` }} />
                                </span>
                                <span className="text-right font-mono text-muted tabular-nums">{service.count}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {alerts.weeks.some((week) => week.total > 0 || week.missed > 0) && (
                <section className="mt-8 grid gap-3">
                    <div>
                        <h2 className="text-[15px] font-medium">Cómo van los avisos</h2>
                        <p className="text-[12.5px] text-muted">Lo que pasa con cada propuesta, por semana y por tipo de acción.</p>
                    </div>
                    <AlertTable columns={alerts.weeks} heads={alerts.weeks.map((week) => weekLabel.format(new Date(week.week)))} />
                    {alerts.classes.length > 0 && <AlertTable columns={alerts.classes} heads={alerts.classes.map((measure) => classLabel[measure.class] ?? measure.class)} />}
                </section>
            )}
        </SettingsLayout>
    );
}
