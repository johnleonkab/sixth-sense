import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { ServiceIconFor } from '@/components/service-icon';
import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type AccountCapability = {
    key: string;
    label: string;
    granted: boolean;
    enabled: boolean;
};

type GithubRepository = {
    id: number;
    full_name: string;
    private: boolean;
    can_push: boolean;
    included: boolean;
    writes_code: boolean;
    is_work: boolean;
};

type ConnectedAccount = {
    id: number;
    name: string | null;
    email: string | null;
    workspace: string | null;
    /** Jira only: whether the person wrote this connection's rules. */
    has_rules?: boolean | null;
    /** GitHub only: the repositories this account can see. */
    repositories?: GithubRepository[] | null;
    avatar_url: string | null;
    status: 'active' | 'needs_reconnection';
    missing_scopes: number;
    connected_at: string;
    capabilities: AccountCapability[];
};

type ProviderConnections = {
    key: 'google' | 'slack' | 'atlassian' | 'notion' | 'github';
    name: string;
    services: string[];
    connectable: boolean;
    accounts: ConnectedAccount[];
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

function RepositoryList({ account }: { account: ConnectedAccount }) {
    const repositories = account.repositories ?? [];
    const [query, setQuery] = useState('');
    const shown = repositories.filter((repository) => repository.full_name.toLowerCase().includes(query.trim().toLowerCase()));
    const writeEnabled = account.capabilities.some((capability) => capability.key === 'github.write' && capability.enabled);
    const hasArchive = repositories.some((repository) => repository.is_work);
    const workspaceError = (usePage().props as { errors?: Record<string, string> }).errors?.workspace;

    const save = (repository: GithubRepository, patch: Partial<Pick<GithubRepository, 'included' | 'writes_code'>>) => {
        const included = patch.included ?? repository.included;
        const writesCode = included ? (patch.writes_code ?? repository.writes_code) : false;

        router.put(
            `/settings/connections/${account.id}/repositories/${repository.id}`,
            { included, writes_code: writesCode },
            { preserveScroll: true },
        );
    };

    return (
        <div className="mt-3 pl-11">
            <div className="mb-2 flex items-center gap-2">
                <p className="text-[12.5px] font-medium text-muted">Repositorios</p>
                <button
                    type="button"
                    onClick={() => router.post(`/settings/connections/${account.id}/repositories`, {}, { preserveScroll: true })}
                    className="rounded-lg px-2 py-1 text-[12.5px] text-accent hover:bg-accent-soft"
                >
                    Actualizar lista
                </button>
                {writeEnabled && !hasArchive && (
                    <button
                        type="button"
                        onClick={() => router.post(`/settings/connections/${account.id}/sense`, {}, { preserveScroll: true })}
                        className="rounded-lg px-2 py-1 text-[12.5px] text-accent hover:bg-accent-soft"
                    >
                        Crear archivo
                    </button>
                )}
            </div>

            {typeof workspaceError === 'string' && <p className="mb-2 text-[13px] text-crit">{workspaceError}</p>}

            {repositories.length > 6 && (
                <input
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Buscar repositorio"
                    className="mb-2 w-full rounded-lg border border-line bg-surface px-3 py-1.5 text-[13px]"
                />
            )}

            {shown.length === 0 ? (
                <p className="rounded-xl border border-dashed border-line px-3 py-2 text-[13px] text-muted">
                    {repositories.length === 0 ? 'Todavía no hay repositorios. Actualiza la lista.' : 'Ningún repositorio con ese nombre.'}
                </p>
            ) : (
                <ul className="max-h-72 divide-y divide-line overflow-auto rounded-xl border border-line">
                    {shown.map((repository) => (
                        <li key={repository.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-[13px]">{repository.full_name}</p>
                                <p className="text-[12px] text-faint">
                                    {repository.private ? 'Privado' : 'Público'}
                                    {repository.is_work && ' · archivo de trabajo'}
                                    {!repository.can_push && ' · sin permiso de escritura en GitHub'}
                                </p>
                            </div>
                            {repository.is_work ? (
                                <button
                                    type="button"
                                    onClick={() => router.post(`/settings/connections/${account.id}/repositories/${repository.id}/sense/sync`, {}, { preserveScroll: true })}
                                    className="rounded-lg px-2 py-1 text-[13px] text-accent hover:bg-accent-soft"
                                >
                                    Sincronizar agentes
                                </button>
                            ) : (
                                repository.can_push &&
                                writeEnabled && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => router.post(`/settings/connections/${account.id}/repositories/${repository.id}/sense`, {}, { preserveScroll: true })}
                                            className="rounded-lg px-2 py-1 text-[13px] text-ink hover:bg-soft"
                                        >
                                            Usar como archivo
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => router.post(`/settings/connections/${account.id}/repositories/${repository.id}/sense/fill`, {}, { preserveScroll: true })}
                                            className="rounded-lg px-2 py-1 text-[13px] text-muted hover:bg-soft"
                                        >
                                            Completar andamiaje
                                        </button>
                                    </>
                                )
                            )}
                            <Switch checked={repository.included} onChange={(included) => save(repository, { included })} className="text-[13px]">
                                Usar
                            </Switch>
                            <Switch
                                checked={repository.included && repository.writes_code}
                                disabled={!repository.included || !repository.can_push || !writeEnabled}
                                onChange={(writesCode) => save(repository, { writes_code: writesCode })}
                                className="text-[13px]"
                            >
                                Actualizar código
                            </Switch>
                        </li>
                    ))}
                </ul>
            )}
        </div>
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
            <div className="flex items-start gap-3 max-md:flex-wrap">
                <AccountAvatar account={account} />

                <div className="min-w-0 flex-1">
                    <p className="truncate text-[13.5px] font-medium">{account.email ?? account.name}</p>
                    <p className="truncate text-[12.5px] text-muted">
                        <AccountStatus account={account} />
                        {account.workspace && <> · {account.workspace}</>}
                        {account.name && account.email && <> · {account.name}</>}
                    </p>
                </div>

                <div className="flex flex-none items-center gap-1 text-[13px] max-md:w-full max-md:flex-wrap">
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

            {provider.key === 'github' && account.repositories && <RepositoryList account={account} />}
        </li>
    );
}

function ProviderCard({ provider }: { provider: ProviderConnections }) {
    const addButtonClass = 'ml-auto flex-none rounded-full border border-line px-3.5 py-1.5 text-[13px]';

    return (
        <section aria-labelledby={`provider-${provider.key}`} className="rounded-2xl border border-line bg-surface">
            <header className="flex items-center gap-3 px-[22px] pt-[18px] max-md:flex-wrap">
                <span aria-hidden className="grid size-8 flex-none place-items-center rounded-[9px] border border-line bg-surface">
                    <ServiceIconFor reference={provider.key} size={20} label={false} />
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
