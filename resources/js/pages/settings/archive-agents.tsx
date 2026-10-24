import { Link, router, usePage } from '@inertiajs/react';

import SettingsLayout from '@/layouts/settings-layout';
import ArchiveNav from '@/pages/settings/archive-nav';

type Agent = {
    slug: string;
    name: string;
    purpose: string;
    cue: string;
};

type Repository = {
    full_name: string;
    writable: boolean;
};

type Props = {
    repository: Repository;
    agents: Agent[];
};

/**
 * Agents copied from the work archive. Each one is agents/{slug}/agent.md.
 */
export default function ArchiveAgents({ repository, agents }: Props) {
    const agentError = (usePage().props as { errors?: Record<string, string> }).errors?.agent;

    return (
        <SettingsLayout
            title="Archivo"
            description={`Agentes de ${repository.full_name}. La cabecera sigue types/agent.yml y el resto del archivo es markdown.`}
        >
            <ArchiveNav />

            {agentError && <p className="mb-4 text-[13px] text-crit">{agentError}</p>}

            {!repository.writable && (
                <p className="mb-4 text-[13px] text-warn">Este archivo es de solo lectura. Permite actualizar su código para crear o cambiar agentes.</p>
            )}

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p className="text-[13px] text-muted">{agents.length === 0 ? 'Ningún agente copiado todavía.' : `${agents.length} ${agents.length === 1 ? 'agente' : 'agentes'}`}</p>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => router.post('/settings/archive/agents/sync', {}, { preserveScroll: true })}
                        className="rounded-full border border-line px-4 py-1.5 text-[13px] hover:bg-soft"
                    >
                        Sincronizar
                    </button>
                    {repository.writable && (
                        <Link href="/settings/archive/agents/create" className="rounded-full bg-ink px-4 py-1.5 text-[13px] font-medium text-bg hover:opacity-90">
                            Nuevo agente
                        </Link>
                    )}
                </div>
            </div>

            {agents.length > 0 && (
                <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
                    {agents.map((agent) => (
                        <li key={agent.slug}>
                            <Link href={`/settings/archive/agents/${agent.slug}`} className="block px-[22px] py-4 hover:bg-soft">
                                <span className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <span className="font-medium">{agent.name}</span>
                                    <span className="font-mono text-[12px] text-faint">agents/{agent.slug}/agent.md</span>
                                </span>
                                <span className="mt-1 block text-[13.5px] text-muted">{agent.purpose}</span>
                                <span className="mt-0.5 block text-[12.5px] text-faint">{agent.cue}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </SettingsLayout>
    );
}
