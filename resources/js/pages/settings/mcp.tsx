import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { buttonClass } from '@/components/button';
import Switch from '@/components/switch';
import SettingsLayout from '@/layouts/settings-layout';

type Tool = { name: string; description: string; read_only: boolean };

type Server = {
    id: number;
    name: string;
    url: string;
    has_token: boolean;
    /** "token", or "oauth" when it is connected by signing in. */
    auth: 'token' | 'oauth';
    signed_in: boolean;
    enabled: boolean;
    checked_at: string | null;
    error: string | null;
    tools: Tool[];
};

const fieldClass = 'w-full rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink outline-none focus:border-accent';

const formatMoment = (iso: string) => new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

function AddServer() {
    const form = useForm({ name: '', url: '', token: '', client_id: '', client_secret: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/settings/mcp', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-2xl border border-line bg-surface px-[22px] py-[18px]">
            <h2 className="text-[15px] font-medium tracking-[-0.01em]">Añadir un servidor</h2>
            <div className="grid gap-3 sm:grid-cols-[minmax(0,12rem)_1fr]">
                <label className="grid gap-1.5">
                    <span className="text-[13px] font-medium">Nombre</span>
                    <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className={fieldClass} placeholder="Linear" required />
                    {form.errors.name && <span className="text-[12.5px] text-crit">{form.errors.name}</span>}
                </label>
                <label className="grid gap-1.5">
                    <span className="text-[13px] font-medium">URL</span>
                    <input
                        value={form.data.url}
                        onChange={(event) => form.setData('url', event.target.value)}
                        type="url"
                        spellCheck={false}
                        className={`${fieldClass} font-mono text-[13px]`}
                        placeholder="https://mcp.ejemplo.com/mcp"
                        required
                    />
                    {form.errors.url && <span className="text-[12.5px] text-crit">{form.errors.url}</span>}
                </label>
            </div>
            <label className="grid gap-1.5">
                <span className="text-[13px] font-medium">Token</span>
                <span className="text-[12.5px] text-muted">
                    Opcional. Déjalo vacío si el servidor pide iniciar sesión (Linear, Notion, Atlassian…): te saldrá el botón «Iniciar sesión». Si lo pones, se envía como «Authorization: Bearer …» y se guarda cifrado.
                </span>
                <input
                    value={form.data.token}
                    onChange={(event) => form.setData('token', event.target.value)}
                    type="password"
                    autoComplete="off"
                    className={`${fieldClass} font-mono text-[13px]`}
                />
            </label>
            <details className="text-[13px]">
                <summary className="cursor-pointer text-muted">Avanzado: servidores que no permiten registrarse solos</summary>
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <label className="grid gap-1.5">
                        <span className="font-medium">Client ID</span>
                        <input value={form.data.client_id} onChange={(event) => form.setData('client_id', event.target.value)} spellCheck={false} className={`${fieldClass} font-mono text-[13px]`} />
                    </label>
                    <label className="grid gap-1.5">
                        <span className="font-medium">Client secret</span>
                        <input
                            value={form.data.client_secret}
                            onChange={(event) => form.setData('client_secret', event.target.value)}
                            type="password"
                            autoComplete="off"
                            className={`${fieldClass} font-mono text-[13px]`}
                        />
                    </label>
                </div>
                <p className="mt-2 text-[12.5px] text-muted">La URL de vuelta que tienes que registrar en ese servidor es {window.location.origin}/settings/mcp/oauth/callback.</p>
            </details>
            <div>
                <button type="submit" disabled={form.processing} className={buttonClass({ variant: 'primary' })}>
                    {form.processing ? 'Conectando…' : 'Conectar'}
                </button>
            </div>
        </form>
    );
}

function ServerCard({ server }: { server: Server }) {
    const toggle = (enabled: boolean) => router.put(`/settings/mcp/${server.id}`, { enabled }, { preserveScroll: true });
    const refresh = () => router.post(`/settings/mcp/${server.id}/refresh`, {}, { preserveScroll: true });
    const remove = () => {
        if (window.confirm(`¿Quitar «${server.name}»?`)) {
            router.delete(`/settings/mcp/${server.id}`, { preserveScroll: true });
        }
    };

    return (
        <section className="rounded-2xl border border-line bg-surface">
            <header className="flex items-start gap-3 px-[22px] pt-[18px] pb-3">
                <div className="min-w-0 flex-1">
                    <h3 className="text-[15px] font-medium tracking-[-0.01em]">{server.name}</h3>
                    <p className="truncate font-mono text-[12px] text-faint">{server.url}</p>
                    <p className="mt-1 text-[12.5px] text-muted">
                        {server.error ? <span className="text-crit">{server.error}</span> : `${server.tools.length} herramientas`}
                        {server.checked_at && ` · comprobado ${formatMoment(server.checked_at)}`}
                        {server.has_token && ' · con token'}
                        {server.auth === 'oauth' && (server.signed_in ? ' · sesión iniciada' : ' · falta iniciar sesión')}
                    </p>
                </div>
                <Switch checked={server.enabled} onChange={toggle} className="mt-1 flex-none">
                    <span className="sr-only">Usar {server.name}</span>
                </Switch>
            </header>

            {server.tools.length > 0 && (
                <ul className="divide-y divide-line border-t border-line">
                    {server.tools.map((tool) => (
                        <li key={tool.name} className="px-[22px] py-2.5 text-[13px]">
                            <span className="flex items-baseline justify-between gap-3">
                                <span className="font-mono">{tool.name}</span>
                                <span className={`shrink-0 text-[12px] ${tool.read_only ? 'text-muted' : 'text-warn'}`}>{tool.read_only ? 'Solo lee' : 'Pide tu sí'}</span>
                            </span>
                            {tool.description && <span className="mt-0.5 line-clamp-2 block text-[12.5px] text-muted">{tool.description}</span>}
                        </li>
                    ))}
                </ul>
            )}

            <footer className="flex flex-wrap gap-2 border-t border-line px-[22px] py-3">
                {server.auth === 'oauth' && (
                    <a
                        href={`/settings/mcp/${server.id}/connect`}
                        className={server.signed_in ? 'rounded-full border border-line px-3 py-1 text-[12.5px] hover:bg-soft' : 'rounded-full bg-ink px-3 py-1 text-[12.5px] font-medium text-bg hover:opacity-90'}
                    >
                        {server.signed_in ? 'Volver a iniciar sesión' : 'Iniciar sesión'}
                    </a>
                )}
                <button type="button" onClick={refresh} className={buttonClass({ size: 'small' })}>
                    Probar de nuevo
                </button>
                <button type="button" onClick={remove} className={buttonClass({ size: 'small', destructive: true })}>
                    Quitar
                </button>
            </footer>
        </section>
    );
}

/**
 * Remote MCP servers the assistant may use. Tools the server marks as
 * read-only run directly; the rest are proposed and wait for a "sí".
 */
export default function Mcp({ servers }: { servers: Server[] }) {
    return (
        <SettingsLayout
            title="MCP"
            description="Conecta servidores MCP remotos por URL, con un token o iniciando sesión, y el asistente podrá usar sus herramientas. Las que solo leen se ejecutan al momento; las que pueden cambiar algo te piden un sí, como el resto de acciones."
        >
            <div className="flex flex-col gap-4">
                {servers.map((server) => (
                    <ServerCard key={server.id} server={server} />
                ))}
                <AddServer />
            </div>
        </SettingsLayout>
    );
}
