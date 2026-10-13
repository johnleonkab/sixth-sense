import { Deferred, Link } from '@inertiajs/react';

import { dayLabel, timeRange } from '@/wingman/format';
import type { WingmanMeetingSummary } from '@/wingman/types';
import WingmanLayout from '@/wingman/wingman-layout';

type WingmanIndexProps = {
    enabled: boolean;
    today?: WingmanMeetingSummary[];
    past: WingmanMeetingSummary[];
};

function MeetingRow({ meeting }: { meeting: WingmanMeetingSummary }) {
    const live = meeting.status === 'live';
    const over = meeting.status === 'ended' || (meeting.status === 'scheduled' && new Date(meeting.ends_at) < new Date());

    return (
        <li>
            <Link
                href={`/wingman/${meeting.id}`}
                className="grid grid-cols-[96px_minmax(0,1fr)_auto] items-baseline gap-4 rounded-xl px-3 py-2.5 transition-colors hover:bg-soft"
            >
                <span className="font-mono text-[12.5px] text-muted tabular-nums">{timeRange(meeting.starts_at, meeting.ends_at)}</span>
                <span className="min-w-0">
                    <span className="block truncate text-[14.5px] font-medium">{meeting.title}</span>
                    <span className="block truncate text-[12.5px] text-muted">
                        {meeting.attendee_count} {meeting.attendee_count === 1 ? 'asistente' : 'asistentes'}
                        {meeting.account ? ` · ${meeting.account}` : ''}
                    </span>
                </span>
                <span className={`text-[12px] ${live ? 'font-medium text-accent' : !over && meeting.prepared ? 'text-ok' : 'text-faint'}`}>
                    {live ? 'En curso' : meeting.status === 'ended' ? 'Terminada' : over ? 'Ya pasó' : meeting.prepared ? 'Contexto listo' : 'Se prepara antes'}
                </span>
            </Link>
        </li>
    );
}

function TodaySkeleton() {
    return (
        <ul aria-label="Buscando las reuniones de hoy" className="space-y-2">
            {[0, 1, 2].map((row) => (
                <li key={row} className="h-[52px] animate-pulse rounded-xl bg-soft" />
            ))}
        </ul>
    );
}

export default function WingmanIndex({ enabled, today, past }: WingmanIndexProps) {
    const byDay = past.reduce<Record<string, WingmanMeetingSummary[]>>((days, meeting) => {
        const key = meeting.starts_at.slice(0, 10);
        (days[key] ??= []).push(meeting);

        return days;
    }, {});

    return (
        <WingmanLayout title="Reuniones">
            <div className="mb-8">
                <h1 className="text-[22px] font-medium tracking-[-0.02em]">Reuniones</h1>
                <p className="mt-1.5 max-w-[560px] text-muted">Tus llamadas de Meet de hoy y lo que Wingman reunió para cada una.</p>
            </div>

            {!enabled && (
                <p className="mb-8 rounded-xl bg-warn-soft px-3.5 py-2.5 text-[13px] text-warn">
                    Wingman está desactivado.{' '}
                    <Link href="/settings/wingman" className="font-medium underline underline-offset-2">
                        Actívalo en Configuración
                    </Link>{' '}
                    para que siga tus llamadas.
                </p>
            )}

            <section aria-labelledby="wingman-today">
                <h2 id="wingman-today" className="mb-2 px-3 text-[13px] text-muted">
                    Hoy
                </h2>
                <Deferred data="today" fallback={<TodaySkeleton />}>
                    {today && today.length > 0 ? (
                        <ul>
                            {today.map((meeting) => (
                                <MeetingRow key={meeting.id} meeting={meeting} />
                            ))}
                        </ul>
                    ) : (
                        <p className="px-3 text-[14px] text-faint">Hoy no tienes llamadas de Meet.</p>
                    )}
                </Deferred>
            </section>

            {past.length > 0 && (
                <section aria-labelledby="wingman-past" className="mt-10">
                    <h2 id="wingman-past" className="mb-2 px-3 text-[13px] text-muted">
                        Anteriores
                    </h2>
                    {Object.entries(byDay).map(([key, meetings]) => (
                        <div key={key} className="mb-4">
                            <h3 className="px-3 pb-1 text-[12px] text-faint">{dayLabel(meetings[0].starts_at)}</h3>
                            <ul>
                                {meetings.map((meeting) => (
                                    <MeetingRow key={meeting.id} meeting={meeting} />
                                ))}
                            </ul>
                        </div>
                    ))}
                </section>
            )}
        </WingmanLayout>
    );
}
