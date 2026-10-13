import { Link, router } from '@inertiajs/react';

import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type AlertSource = {
    key: 'gmail' | 'slack' | 'calendar' | 'agenda';
    name: string;
    description: string;
    enabled: boolean;
    /** Capability an account needs to be watched, e.g. "Leer el correo". */
    capability: string;
    accounts: { id: number; email: string | null; watched: boolean }[];
};

const sourceColor: Record<AlertSource['key'], string> = {
    gmail: 'bg-gmail',
    slack: 'bg-slack',
    agenda: 'bg-ok',
    calendar: 'bg-accent',
};

function SourceCard({ source }: { source: AlertSource }) {
    const watched = source.accounts.filter((account) => account.watched);
    const toggle = (enabled: boolean) => router.put(`/settings/alerts/${source.key}`, { enabled }, { preserveScroll: true });

    return (
        <section aria-labelledby={`alerts-${source.key}`} className="rounded-2xl border border-line bg-surface">
            <header className="flex items-start gap-3 px-[22px] pt-[18px] pb-4">
                <span
                    aria-hidden
                    className={`grid size-8 flex-none place-items-center rounded-[9px] text-[13px] font-semibold text-bg ${sourceColor[source.key]}`}
                >
                    {source.name.charAt(0)}
                </span>

                <div className="min-w-0 flex-1">
                    <h2 id={`alerts-${source.key}`} className="text-[15px] font-medium tracking-[-0.01em]">
                        {source.name}
                    </h2>
                    <p className="mt-0.5 max-w-[480px] text-[13px] text-muted">{source.description}</p>
                </div>

                <Switch checked={source.enabled} onChange={toggle} className="mt-1 flex-none">
                    <span className="sr-only">Avisos de {source.name}</span>
                </Switch>
            </header>

            <div className="border-t border-line px-[22px] py-4 text-[13px]">
                {watched.length === 0 ? (
                    <p className="rounded-xl bg-warn-soft px-3.5 py-2.5 text-warn">
                        Ninguna cuenta tiene activado «{source.capability}».{' '}
                        <Link href="/settings/connections" className="font-medium underline underline-offset-2">
                            Revísalo en Conexiones
                        </Link>
                        .
                    </p>
                ) : (
                    <>
                        <p className={`mb-2 ${source.enabled ? 'text-muted' : 'text-faint'}`}>
                            {source.enabled ? 'Vigilando' : 'Cuando lo actives, vigilaré'} {watched.length === 1 ? 'esta cuenta' : `estas ${watched.length} cuentas`}:
                        </p>
                        <ul className="flex flex-wrap gap-1.5">
                            {watched.map((account) => (
                                <li
                                    key={account.id}
                                    className={`flex items-center gap-1.5 rounded-full bg-soft px-2.5 py-1 text-[12.5px] ${source.enabled ? 'text-ink' : 'text-muted'}`}
                                >
                                    <i aria-hidden className={`size-1.5 rounded-full ${source.enabled ? 'bg-ok' : 'bg-faint'}`} />
                                    {account.email}
                                </li>
                            ))}
                        </ul>
                        {source.accounts.length > watched.length && (
                            <p className="mt-3 text-[12px] text-faint">
                                {source.accounts.length - watched.length === 1 ? 'Otra cuenta no se vigila' : `${source.accounts.length - watched.length} cuentas no se vigilan`}{' '}
                                porque no tienen activado «{source.capability}».
                            </p>
                        )}
                    </>
                )}
            </div>
        </section>
    );
}

export default function Alerts({ sources }: { sources: AlertSource[] }) {
    return (
        <SettingsLayout
            title="Avisos"
            description="Lo que sixth-sense te cuenta sin que preguntes. Solo avisa de lo importante, con un sonido, y siempre te dice por qué."
        >
            <div className="flex flex-col gap-4">
                {sources.map((source) => (
                    <SourceCard key={source.key} source={source} />
                ))}
            </div>
        </SettingsLayout>
    );
}
