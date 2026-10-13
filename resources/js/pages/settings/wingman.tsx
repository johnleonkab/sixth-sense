import { Link, router } from '@inertiajs/react';

import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type WingmanSettingsProps = {
    enabled: boolean;
    /** Google accounts whose calendars Wingman reads. */
    calendars: string[];
    demo: boolean;
};

export default function WingmanSettings({ enabled, calendars, demo }: WingmanSettingsProps) {
    const toggle = (value: boolean) => router.put('/settings/wingman', { enabled: value }, { preserveScroll: true });
    const noCalendar = calendars.length === 0 && !demo;

    return (
        <SettingsLayout title="Wingman" description="Te acompaña en tus llamadas de Google Meet: antes, te prepara el contexto; durante, escucha sin que se note y te aporta lo que haga falta.">
            <section className="rounded-2xl border border-line bg-surface">
                <header className="flex items-start gap-3 px-[22px] pt-[18px] pb-4">
                    <div className="min-w-0 flex-1">
                        <h2 className="text-[15px] font-medium tracking-[-0.01em]">Seguir mis llamadas</h2>
                        <p className="mt-0.5 max-w-[480px] text-[13px] text-muted">
                            Busca en tus calendarios las reuniones de hoy con enlace de Meet y, media hora antes, reúne lo que conviene tener a mano: tickets, páginas, documentos y los últimos emails con los asistentes.
                        </p>
                    </div>

                    <Switch checked={enabled} onChange={toggle} disabled={noCalendar && !enabled} className="mt-1 flex-none">
                        <span className="sr-only">Activar Wingman</span>
                    </Switch>
                </header>

                <div className="border-t border-line px-[22px] py-4 text-[13px]">
                    {noCalendar ? (
                        <p className="rounded-xl bg-warn-soft px-3.5 py-2.5 text-warn">
                            Ninguna cuenta de Google tiene activado «Leer el calendario».{' '}
                            <Link href="/settings/connections" className="font-medium underline underline-offset-2">
                                Revísalo en Conexiones
                            </Link>
                            .
                        </p>
                    ) : (
                        <p className="text-muted">
                            {demo ? 'Modo demo: usa el calendario de ejemplo.' : `Calendarios: ${calendars.join(', ')}.`}
                            {enabled && (
                                <>
                                    {' '}
                                    <Link href="/wingman" className="font-medium text-accent hover:underline">
                                        Ver las reuniones de hoy →
                                    </Link>
                                </>
                            )}
                        </p>
                    )}
                </div>
            </section>
        </SettingsLayout>
    );
}
