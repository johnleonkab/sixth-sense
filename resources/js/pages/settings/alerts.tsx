import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { deviceSubscribed, disableDevicePush, enableDevicePush } from '@/alerts/push';
import type { ActionState } from '@/actions/types';
import { AutonomyLevels, AutonomyNumbers, type AutonomyBoard } from '@/canvas/slack/slack-autonomy';
import { buttonClass } from '@/components/button';
import { ServiceIconFor } from '@/components/service-icon';
import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type AlertSource = {
    key: 'gmail' | 'slack' | 'calendar' | 'agenda' | 'jira' | 'questions' | 'notion';
    name: string;
    description: string;
    enabled: boolean;
    /** Capability an account needs to be watched, e.g. "Leer el correo". */
    capability: string;
    sound: string | null;
    read_aloud: boolean;
    senders: { id: number; email: string; name: string | null }[];
    accounts: { id: number; email: string | null; watched: boolean }[];
};

type SoundChoice = { key: string; label: string };

function SourceCard({ source, sounds }: { source: AlertSource; sounds: SoundChoice[] }) {
    const watched = source.accounts.filter((account) => account.watched);
    const toggle = (enabled: boolean) => router.put(`/settings/alerts/${source.key}`, { enabled }, { preserveScroll: true });

    return (
        <section aria-labelledby={`alerts-${source.key}`} className="rounded-2xl border border-line bg-surface">
            <header className="flex items-start gap-3 px-[22px] pt-[18px] pb-4">
                <span aria-hidden className="grid size-8 flex-none place-items-center rounded-[9px] border border-line bg-surface">
                    <ServiceIconFor reference={source.key} size={20} label={false} />
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
                {source.sound !== null && (
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-[13px] text-muted">
                            Sonido
                            <select
                                aria-label={`Sonido para ${source.name}`}
                                value={source.sound}
                                onChange={(event) =>
                                    router.put(
                                        `/settings/alerts/${source.key}/sound`,
                                        { sound: event.target.value, read_aloud: source.read_aloud },
                                        { preserveScroll: true },
                                    )
                                }
                                className="rounded-lg border border-line bg-surface px-2 py-1 text-[13px] text-ink"
                            >
                                {sounds.map((sound) => (
                                    <option key={sound.key} value={sound.key}>
                                        {sound.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex items-center gap-2 text-[13px]">
                            <input
                                type="checkbox"
                                checked={source.read_aloud}
                                onChange={(event) =>
                                    router.put(
                                        `/settings/alerts/${source.key}/sound`,
                                        { sound: source.sound, read_aloud: event.target.checked },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            Leer en voz alta
                        </label>
                    </div>
                )}
                {source.key === 'gmail' && (
                    <div className="mt-4">
                        <p className="mb-2 text-[13px] text-muted">Remitentes importantes</p>
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const data = new FormData(event.currentTarget);
                                router.post(
                                    '/settings/alerts/gmail/senders',
                                    { email: String(data.get('email') ?? ''), name: String(data.get('name') ?? '') },
                                    { preserveScroll: true },
                                );
                                event.currentTarget.reset();
                            }}
                        >
                            <input
                                name="email"
                                type="email"
                                required
                                placeholder="cliente@ejemplo.com"
                                aria-label="Email del remitente"
                                className="min-w-0 flex-1 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[13px]"
                            />
                            <input
                                name="name"
                                type="text"
                                placeholder="Nombre"
                                aria-label="Nombre del remitente"
                                className="w-36 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[13px]"
                            />
                            <button type="submit" className="rounded-lg bg-ink px-3 py-1.5 text-[13px] text-bg">
                                Marcar
                            </button>
                        </form>
                        <ul className="mt-2 flex flex-col gap-1">
                            {source.senders.map((sender) => (
                                <li key={sender.id} className="flex items-center justify-between gap-2 text-[13px]">
                                    <span>
                                        {sender.name ? `${sender.name} · ` : ''}
                                        {sender.email}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => router.delete(`/settings/alerts/gmail/senders/${sender.id}`, { preserveScroll: true })}
                                        className="text-muted hover:text-ink"
                                    >
                                        Quitar
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </section>
    );
}

function DeviceAlerts({ vapidPublicKey }: { vapidPublicKey: string | null }) {
    const [on, setOn] = useState(false);
    const [ready, setReady] = useState(vapidPublicKey === null);
    const [note, setNote] = useState<string | null>(null);

    useEffect(() => {
        if (vapidPublicKey === null) {
            return;
        }

        let active = true;

        void deviceSubscribed().then((subscribed) => {
            if (!active) {
                return;
            }

            setOn(subscribed);
            setReady(true);
        });

        return () => {
            active = false;
        };
    }, [vapidPublicKey]);

    const toggle = async (checked: boolean) => {
        if (vapidPublicKey === null) {
            return;
        }

        setNote(null);

        try {
            if (checked) {
                await enableDevicePush(vapidPublicKey);
            } else {
                await disableDevicePush();
            }

            setOn(checked);
        } catch {
            setOn(false);
            setNote(checked ? 'Este navegador no ha dado permiso para avisarte.' : 'No he podido quitar el aviso de este dispositivo.');
        }
    };

    return (
        <section aria-labelledby="alerts-device" className="rounded-2xl border border-line bg-surface px-[22px] py-[18px]">
            <Switch checked={on} disabled={vapidPublicKey === null || !ready} onChange={(checked) => void toggle(checked)}>
                <span id="alerts-device" className="text-[15px] font-medium tracking-[-0.01em]">
                    Avisar en este dispositivo
                </span>
            </Switch>
            <p className="mt-1 max-w-[480px] pl-10 text-[13px] text-muted">
                Los avisos importantes llegan también como notificación del sistema, con el motivo. En el iPhone solo funciona con la app en la pantalla de inicio.
            </p>
            {vapidPublicKey === null && <p className="mt-2 pl-10 text-[13px] text-warn">Los avisos del sistema no están configurados.</p>}
            {note && <p className="mt-2 pl-10 text-[13px] text-warn">{note}</p>}
        </section>
    );
}

function SlackAutonomySettings({ board, pending }: { board: AutonomyBoard; pending: ActionState | null }) {
    const error = (usePage().props as { errors?: Record<string, string> }).errors?.slack_autonomy;

    return (
        <section aria-labelledby="slack-autonomy" className="rounded-2xl border border-line bg-surface px-[22px] py-[18px]">
            <h2 id="slack-autonomy" className="text-[15px] font-medium tracking-[-0.01em]">
                Autonomía de Slack
            </h2>
            <p className="mt-0.5 max-w-[480px] text-[13px] text-muted">
                Ver explica. Proponer deja un borrador. Hacer envía y deja unos segundos para deshacer. Subir a hacer espera tu sí, y solo si esa acción ya se acepta.
            </p>
            <div className="mt-3">
                <AutonomyLevels levels={board.levels} />
                <AutonomyNumbers board={board.board} />
            </div>
            {typeof error === 'string' && <p className="mt-3 text-[13px] text-warn">{error}</p>}
            {pending && (
                <form
                    className="mt-4 flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(`/settings/slack-autonomy/${pending.id}/confirm`, {}, { preserveScroll: true });
                    }}
                >
                    <p className="min-w-0 flex-1 text-[13px]">{pending.label}</p>
                    <button type="button" onClick={() => router.post(`/settings/slack-autonomy/${pending.id}/cancel`, {}, { preserveScroll: true })} className={buttonClass({ size: 'small' })}>
                        Cancelar
                    </button>
                    <button type="submit" className={buttonClass({ variant: 'primary', size: 'small' })}>
                        Sí, subir
                    </button>
                </form>
            )}
        </section>
    );
}

export default function Alerts({
    sources,
    sounds,
    vapidPublicKey,
    slackAutonomy,
    autonomyAction,
}: {
    sources: AlertSource[];
    sounds: SoundChoice[];
    vapidPublicKey: string | null;
    slackAutonomy: AutonomyBoard;
    autonomyAction: ActionState | null;
}) {
    return (
        <SettingsLayout
            title="Avisos"
            description="Lo que sixth-sense te cuenta sin que preguntes. Solo avisa de lo importante, con un sonido, y siempre te dice por qué."
        >
            <div className="flex flex-col gap-4">
                <DeviceAlerts vapidPublicKey={vapidPublicKey} />
                <SlackAutonomySettings board={slackAutonomy} pending={autonomyAction} />
                {sources.map((source) => (
                    <SourceCard key={source.key} source={source} sounds={sounds} />
                ))}
            </div>
        </SettingsLayout>
    );
}
