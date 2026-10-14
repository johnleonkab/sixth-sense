import { router } from '@inertiajs/react';

import SettingsLayout from '@/layouts/settings-layout';

type ImpactProps = {
    period: '7' | '30' | 'all';
    summary: { minutes: number; proposals: number; slots: number; events: number; by_service: { service: string; count: number }[] };
    weekly: { week: string; minutes: number }[];
    rates: { proposal: number; slot: number };
};

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

export default function Impact({ period, summary, weekly, rates }: ImpactProps) {
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
                                <span>{service.service}</span>
                                <span className="h-2 overflow-hidden rounded-full bg-soft">
                                    <span className="block h-full rounded-full bg-accent" style={{ width: `${(service.count / topService) * 100}%` }} />
                                </span>
                                <span className="text-right font-mono text-muted tabular-nums">{service.count}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </SettingsLayout>
    );
}
