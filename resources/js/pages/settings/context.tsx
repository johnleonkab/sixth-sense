import { router, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type Workstream = {
    name: string;
    what: string;
    why: string | null;
    with: string[];
    where: string[];
    next: string | null;
};

type WorkContext = {
    enabled: boolean;
    status: 'idle' | 'running' | 'ready' | 'failed';
    status_label: string;
    error: string | null;
    started_at: string | null;
    generated_at: string | null;
    /** On, built and recent enough: the assistant gets it in every conversation. */
    usable: boolean;
    sources: { slack?: number; gmail?: number; docs?: number; notion?: number; failed?: string[] } | null;
    digest: {
        summary: string;
        workstreams: Workstream[];
        people: { name: string; relation: string }[];
        open_loops: string[];
    } | null;
};

const statusDot: Record<WorkContext['status'], string> = {
    idle: 'bg-faint',
    running: 'bg-accent animate-pulse',
    ready: 'bg-ok',
    failed: 'bg-crit',
};

const sourceNames: [keyof NonNullable<WorkContext['sources']>, string, string][] = [
    ['slack', 'Slack', 'mensajes'],
    ['gmail', 'Gmail', 'emails'],
    ['docs', 'Google Docs', 'documentos'],
    ['notion', 'Notion', 'páginas'],
];

const formatMoment = (iso: string) =>
    new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

function Status({ context, runAt }: { context: WorkContext; runAt: string }) {
    const building = context.status === 'running';
    const build = () => router.post('/settings/context/build', {}, { preserveScroll: true });

    return (
        <div className="border-t border-line px-[22px] py-4 text-[13px]">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <p className="flex items-center gap-2 font-medium">
                        <i aria-hidden className={`size-2 rounded-full ${statusDot[context.status]}`} />
                        {context.status_label}
                    </p>
                    <p className="mt-0.5 text-muted">
                        {context.generated_at ? `Último: ${formatMoment(context.generated_at)}. ` : 'Aún no se ha generado. '}
                        {context.enabled ? `Se actualiza cada noche a las ${runAt}.` : 'Actívalo para que se actualice cada noche.'}
                    </p>
                </div>

                <button
                    type="button"
                    onClick={build}
                    disabled={!context.enabled || building}
                    className="rounded-lg border border-line px-3 py-1.5 text-[13px] font-medium hover:bg-soft disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {building ? 'Generando…' : 'Generar ahora'}
                </button>
            </div>

            {context.error && <p className="mt-3 rounded-xl bg-crit-soft px-3.5 py-2.5 text-crit">{context.error}</p>}

            {context.sources && (
                <ul className="mt-3 flex flex-wrap gap-1.5">
                    {sourceNames.map(([key, name, unit]) => {
                        const failed = context.sources?.failed?.includes(key);

                        return (
                            <li key={key} className={`rounded-full bg-soft px-2.5 py-1 text-[12.5px] ${failed ? 'text-crit' : 'text-muted'}`}>
                                {name} · {failed ? 'no se pudo leer' : `${context.sources?.[key] ?? 0} ${unit}`}
                            </li>
                        );
                    })}
                </ul>
            )}

            {context.enabled && context.generated_at && !context.usable && context.status !== 'running' && (
                <p className="mt-3 rounded-xl bg-warn-soft px-3.5 py-2.5 text-warn">
                    Tiene más de tres días: el asistente no lo usa hasta que se vuelva a generar.
                </p>
            )}
        </div>
    );
}

function Digest({ digest, usable }: { digest: NonNullable<WorkContext['digest']>; usable: boolean }) {
    return (
        <section aria-labelledby="context-digest" className="rounded-2xl border border-line bg-surface px-[22px] py-[18px]">
            <h2 id="context-digest" className="text-[15px] font-medium tracking-[-0.01em]">
                Lo que sabe el asistente
            </h2>
            <p className="mt-0.5 text-[12.5px] text-faint">
                {usable ? 'Lo lee al empezar cada conversación.' : 'Ahora mismo no lo usa.'}
            </p>

            <p className="mt-4 text-[14px] leading-relaxed">{digest.summary}</p>

            {digest.workstreams.length > 0 && (
                <>
                    <h3 className="mt-6 mb-2 text-[13px] text-muted">En qué estás</h3>
                    <ul className="flex flex-col divide-y divide-line">
                        {digest.workstreams.map((stream) => (
                            <li key={stream.name} className="py-3 first:pt-0">
                                <p className="text-[14px] font-medium">{stream.name}</p>
                                <p className="mt-0.5 text-[13px]">{stream.what}</p>
                                <dl className="mt-1.5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-[12.5px] text-muted">
                                    {stream.why && (
                                        <>
                                            <dt className="text-faint">Por qué</dt>
                                            <dd>{stream.why}</dd>
                                        </>
                                    )}
                                    {stream.with.length > 0 && (
                                        <>
                                            <dt className="text-faint">Con</dt>
                                            <dd>{stream.with.join(', ')}</dd>
                                        </>
                                    )}
                                    {stream.where.length > 0 && (
                                        <>
                                            <dt className="text-faint">Dónde</dt>
                                            <dd>{stream.where.join(' · ')}</dd>
                                        </>
                                    )}
                                    {stream.next && (
                                        <>
                                            <dt className="text-faint">Siguiente</dt>
                                            <dd>{stream.next}</dd>
                                        </>
                                    )}
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {digest.people.length > 0 && (
                <>
                    <h3 className="mt-6 mb-2 text-[13px] text-muted">Con quién</h3>
                    <ul className="flex flex-wrap gap-1.5">
                        {digest.people.map((person) => (
                            <li key={person.name} title={person.relation} className="rounded-full bg-soft px-2.5 py-1 text-[12.5px]">
                                {person.name}
                                {person.relation && <span className="text-muted"> · {person.relation}</span>}
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {digest.open_loops.length > 0 && (
                <>
                    <h3 className="mt-6 mb-2 text-[13px] text-muted">Pendiente</h3>
                    <ul className="list-disc space-y-1 pl-5 text-[13px]">
                        {digest.open_loops.map((loop) => (
                            <li key={loop}>{loop}</li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

export default function Context({ context, run_at: runAt }: { context: WorkContext; run_at: string }) {
    const building = context.status === 'running';
    const { start, stop } = usePoll(3000, { only: ['context'] }, { autoStart: false });

    useEffect(() => {
        if (building) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [building, start, stop]);

    const toggle = (enabled: boolean) => router.put('/settings/context', { enabled }, { preserveScroll: true });

    return (
        <SettingsLayout
            title="Contexto"
            description="Cada noche, sixth-sense repasa tu semana para que el asistente sepa en qué estás, por qué y con quién, sin que tengas que explicárselo."
        >
            <div className="flex flex-col gap-4">
                <section aria-labelledby="context-nightly" className="rounded-2xl border border-line bg-surface">
                    <header className="flex items-start gap-3 px-[22px] pt-[18px] pb-4">
                        <div className="min-w-0 flex-1">
                            <h2 id="context-nightly" className="text-[15px] font-medium tracking-[-0.01em]">
                                Contexto de trabajo
                            </h2>
                            <p className="mt-0.5 max-w-[480px] text-[13px] text-muted">
                                Lee los últimos 5 días de Slack, 7 de Gmail y los documentos de Google Docs y Notion que se han creado, compartido o
                                editado esta semana. Solo lectura: nunca cambia nada en tus servicios.
                            </p>
                        </div>

                        <Switch checked={context.enabled} onChange={toggle} className="mt-1 flex-none">
                            <span className="sr-only">Contexto de trabajo</span>
                        </Switch>
                    </header>

                    <Status context={context} runAt={runAt} />
                </section>

                {context.digest && <Digest digest={context.digest} usable={context.usable} />}
            </div>
        </SettingsLayout>
    );
}
