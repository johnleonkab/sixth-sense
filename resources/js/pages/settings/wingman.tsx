import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type WingmanSettingsProps = {
    enabled: boolean;
    /** Google accounts whose calendars Wingman reads. */
    calendars: string[];
    demo: boolean;
    extension: { connected: boolean; used_at: string | null; server: string };
};

const usedFormat = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

function CopyField({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div>
            <span className="mb-1 block text-[12px] text-muted">{label}</span>
            <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-lg bg-soft px-2.5 py-1.5 font-mono text-[12.5px]">{value}</code>
                <button
                    type="button"
                    onClick={() => void navigator.clipboard.writeText(value).then(() => setCopied(true))}
                    className="flex-none rounded-full border border-line px-3 py-1 text-[12.5px] text-muted hover:border-accent hover:text-accent"
                >
                    {copied ? 'Copiado' : 'Copiar'}
                </button>
            </div>
        </div>
    );
}

function ExtensionCard({ extension }: { extension: WingmanSettingsProps['extension'] }) {
    const token = usePage().flash.wingman_token;
    const issue = () => router.post('/settings/wingman/token', {}, { preserveScroll: true });
    const revoke = () => router.delete('/settings/wingman/token', { preserveScroll: true });

    return (
        <section className="mt-5 rounded-2xl border border-line bg-surface">
            <header className="px-[22px] pt-[18px] pb-4">
                <h2 className="text-[15px] font-medium tracking-[-0.01em]">Extensión «Wingman para Meet»</h2>
                <p className="mt-0.5 max-w-[520px] text-[13px] text-muted">
                    Detecta cuándo entras en una llamada y le pasa a Wingman lo que se dice, desde tu navegador: en la llamada nadie ve nada.
                </p>
            </header>

            <div className="space-y-4 border-t border-line px-[22px] py-4 text-[13px]">
                <ol className="list-decimal space-y-1 pl-4 text-muted">
                    <li>
                        En Chrome, abre <code className="font-mono text-[12px]">chrome://extensions</code>, activa el modo desarrollador y pulsa «Cargar descomprimida» con la carpeta{' '}
                        <code className="font-mono text-[12px]">extensions/wingman-meet</code>.
                    </li>
                    <li>Genera un token aquí y pégalo, con el servidor, en la ventana de la extensión.</li>
                    <li>En Meet, pon los subtítulos en español (Ajustes → Subtítulos).</li>
                </ol>

                {token ? (
                    <div className="space-y-3 rounded-xl border border-accent/40 bg-accent-soft/40 p-3.5">
                        <p className="font-medium">Cópialo ahora: no se volverá a mostrar.</p>
                        <CopyField label="Servidor" value={extension.server} />
                        <CopyField label="Token" value={token} />
                    </div>
                ) : (
                    <p className="text-muted">
                        {extension.connected
                            ? extension.used_at
                                ? `Conectada · último uso ${usedFormat.format(new Date(extension.used_at))}.`
                                : 'Token generado, todavía sin usar.'
                            : 'Sin conectar.'}
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={issue} className="rounded-full bg-accent px-3.5 py-1.5 text-[13px] font-medium text-accent-ink">
                        {extension.connected ? 'Generar un token nuevo' : 'Generar token'}
                    </button>
                    {extension.connected && (
                        <button type="button" onClick={revoke} className="rounded-full border border-line px-3.5 py-1.5 text-[13px] text-muted hover:border-crit hover:text-crit">
                            Desconectar
                        </button>
                    )}
                </div>
            </div>
        </section>
    );
}

export default function WingmanSettings({ enabled, calendars, demo, extension }: WingmanSettingsProps) {
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

            {enabled && <ExtensionCard extension={extension} />}
        </SettingsLayout>
    );
}
