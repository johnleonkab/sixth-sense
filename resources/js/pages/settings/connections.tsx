import { Link, router } from '@inertiajs/react';

import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type AccountCapability = {
    key: string;
    label: string;
    granted: boolean;
    enabled: boolean;
};

type ConnectedAccount = {
    id: number;
    name: string | null;
    email: string | null;
    workspace: string | null;
    /** Jira only: whether the person wrote this connection's rules. */
    has_rules?: boolean | null;
    avatar_url: string | null;
    status: 'active' | 'needs_reconnection';
    missing_scopes: number;
    connected_at: string;
    capabilities: AccountCapability[];
};

type ProviderConnections = {
    key: 'google' | 'slack' | 'atlassian' | 'notion';
    name: string;
    services: string[];
    connectable: boolean;
    accounts: ConnectedAccount[];
};

const providerColor: Record<ProviderConnections['key'], string> = {
    google: 'bg-gmail',
    slack: 'bg-slack',
    atlassian: 'bg-jira',
    notion: 'bg-notion',
};

const authorizeUrl = (provider: ProviderConnections) => `/settings/connections/${provider.key}/redirect`;

function initials(account: ConnectedAccount): string {
    const source = account.name || account.email || '?';

    return source
        .split(/[\s@.]+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function AccountAvatar({ account }: { account: ConnectedAccount }) {
    if (account.avatar_url) {
        return <img src={account.avatar_url} alt="" referrerPolicy="no-referrer" className="size-8 flex-none rounded-full" />;
    }

    return (
        <span aria-hidden className="grid size-8 flex-none place-items-center rounded-full bg-soft text-[11px] font-medium text-muted">
            {initials(account)}
        </span>
    );
}

function AccountStatus({ account }: { account: ConnectedAccount }) {
    if (account.status === 'needs_reconnection') {
        return <span className="text-warn">Hay que volver a conectarla</span>;
    }

    if (account.missing_scopes > 0) {
        return <span className="text-warn">Faltan permisos</span>;
    }

    return <span className="text-ok">Conectada</span>;
}

function CapabilitySwitch({ account, capability }: { account: ConnectedAccount; capability: AccountCapability }) {
    const toggle = (enabled: boolean) =>
        router.put(`/settings/connections/${account.id}/capabilities/${capability.key}`, { enabled }, { preserveScroll: true });

    return (
        <li>
            <Switch
                checked={capability.enabled}
                disabled={!capability.granted}
                onChange={toggle}
                className="w-full rounded-lg px-2 py-1.5 text-[13px] hover:bg-soft disabled:hover:bg-transparent"
            >
                <span className={capability.granted ? 'text-ink' : 'text-faint'}>{capability.label}</span>
                {!capability.granted && <span className="text-[12px] text-faint">· sin permiso</span>}
            </Switch>
        </li>
    );
}

function AccountRow({ provider, account }: { provider: ProviderConnections; account: ConnectedAccount }) {
    const needsAttention = account.status === 'needs_reconnection' || account.missing_scopes > 0;

    const disconnect = () => {
        if (window.confirm(`¿Desconectar ${account.email ?? 'esta cuenta'}? sixth-sense dejará de poder consultarla.`)) {
            router.delete(`/settings/connections/${account.id}`, { preserveScroll: true });
        }
    };

    return (
        <li className="py-3 first:pt-0 last:pb-0">
            <div className="flex items-center gap-3">
                <AccountAvatar account={account} />

                <div className="min-w-0 flex-1">
                    <p className="truncate text-[13.5px] font-medium">{account.email ?? account.name}</p>
                    <p className="truncate text-[12.5px] text-muted">
                        <AccountStatus account={account} />
                        {account.workspace && <> · {account.workspace}</>}
                        {account.name && account.email && <> · {account.name}</>}
                    </p>
                </div>

                <div className="flex flex-none items-center gap-1 text-[13px]">
                    {provider.key === 'atlassian' && (
                        <Link
                            href={`/settings/connections/${account.id}/rules`}
                            className={`rounded-lg px-2.5 py-1 font-medium hover:bg-accent-soft ${account.has_rules ? 'text-accent' : 'text-warn'}`}
                        >
                            {account.has_rules ? 'Reglas de Jira' : 'Escribir reglas'}
                        </Link>
                    )}
                    {needsAttention && (
                        <a href={authorizeUrl(provider)} className="rounded-lg px-2.5 py-1 font-medium text-accent hover:bg-accent-soft">
                            Reconectar
                        </a>
                    )}
                    <button
                        type="button"
                        onClick={disconnect}
                        className="rounded-lg px-2.5 py-1 text-muted hover:bg-crit-soft hover:text-crit"
                    >
                        Desconectar
                    </button>
                </div>
            </div>

            <ul
                aria-label={`Qué puede hacer sixth-sense con ${account.email ?? 'esta cuenta'}`}
                className="mt-2 -ml-2 grid gap-x-4 pl-11 sm:grid-cols-2"
            >
                {account.capabilities.map((capability) => (
                    <CapabilitySwitch key={capability.key} account={account} capability={capability} />
                ))}
            </ul>
        </li>
    );
}

function ProviderCard({ provider }: { provider: ProviderConnections }) {
    const addButtonClass = 'ml-auto flex-none rounded-full border border-line px-3.5 py-1.5 text-[13px]';

    return (
        <section aria-labelledby={`provider-${provider.key}`} className="rounded-2xl border border-line bg-surface">
            <header className="flex items-center gap-3 px-[22px] pt-[18px]">
                <span
                    aria-hidden
                    className={`grid size-8 flex-none place-items-center rounded-[9px] text-[13px] font-semibold text-bg ${providerColor[provider.key]}`}
                >
                    {provider.name.charAt(0)}
                </span>

                <div className="min-w-0">
                    <h2 id={`provider-${provider.key}`} className="text-[15px] font-medium tracking-[-0.01em]">
                        {provider.name}
                    </h2>
                    <p className="truncate text-[12.5px] text-muted">{provider.services.join(' · ')}</p>
                </div>

                {provider.connectable ? (
                    <a href={authorizeUrl(provider)} className={`${addButtonClass} text-ink hover:bg-soft`}>
                        + Añadir cuenta
                    </a>
                ) : (
                    <button type="button" disabled title="Disponible pronto" className={`${addButtonClass} cursor-not-allowed text-faint`}>
                        + Añadir cuenta
                    </button>
                )}
            </header>

            <div className="px-[22px] pt-4 pb-[22px]">
                {provider.accounts.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-line px-4 py-3 text-[13px] text-muted">
                        Ninguna cuenta conectada.
                    </p>
                ) : (
                    <ul className="divide-y divide-line">
                        {provider.accounts.map((account) => (
                            <AccountRow key={account.id} provider={provider} account={account} />
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

export default function Connections({ providers }: { providers: ProviderConnections[] }) {
    return (
        <SettingsLayout
            title="Conexiones"
            description="Las cuentas que sixth-sense puede consultar. Puedes conectar varias cuentas de cada servicio, por ejemplo la del trabajo y la personal."
        >
            <div className="flex flex-col gap-4">
                {providers.map((provider) => (
                    <ProviderCard key={provider.key} provider={provider} />
                ))}
            </div>
        </SettingsLayout>
    );
}
