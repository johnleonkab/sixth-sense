import { Link, useForm } from '@inertiajs/react';

import { buttonClass } from '@/components/button';
import SettingsLayout from '@/layouts/settings-layout';

type Props = {
    account: { id: number; email: string | null; site: string | null; site_url: string | null };
    rules: string;
    saved: boolean;
    template: string;
    understood: { projects: string[]; jql: string | null; link_type: string; components: string[]; sections: string[] };
    max_length: number;
};

/**
 * The rules of one Jira connection, in markdown: how the team writes epics,
 * stories and subtasks, what each priority means, how dependencies are
 * marked… The assistant follows them; a few are used by the app directly.
 */
export default function JiraRules({ account, rules, saved, template, understood, max_length }: Props) {
    const form = useForm({ rules });

    return (
        <SettingsLayout
            title={`Reglas de Jira${account.site ? ` · ${account.site}` : ''}`}
            description="Cómo trabaja tu equipo en este Jira. El asistente las sigue al crear, editar, priorizar, enlazar y comentar, y vigila las menciones solo en los proyectos que digas."
        >
            <div className="mb-3 flex items-center gap-3 text-[12.5px] text-muted">
                <Link href="/settings/connections" className="hover:text-ink">
                    ← Conexiones
                </Link>
                <span>{account.email}</span>
                {!saved && <span className="rounded-full bg-warn-soft px-2 py-[2px] text-warn">Todavía es la plantilla: adáptala y guárdala</span>}
            </div>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(`/settings/connections/${account.id}/rules`, { preserveScroll: true });
                }}
                className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_260px]"
            >
                <div>
                    <textarea
                        value={form.data.rules}
                        onChange={(event) => form.setData('rules', event.target.value)}
                        spellCheck={false}
                        aria-label="Reglas de Jira, en markdown"
                        className="h-[62vh] w-full resize-y rounded-2xl border border-line bg-surface p-4 font-mono text-[13px] leading-[1.6] text-ink outline-none focus:border-accent"
                    />
                    {form.errors.rules && <p className="mt-1 text-[12.5px] text-crit">{form.errors.rules}</p>}
                    <div className="mt-3 flex items-center gap-2">
                        <button type="submit" disabled={form.processing || !form.isDirty} className={buttonClass({ variant: 'primary' })}>
                            {form.processing ? 'Guardando…' : 'Guardar'}
                        </button>
                        <button
                            type="button"
                            onClick={() => window.confirm('¿Sustituir lo escrito por la plantilla?') && form.setData('rules', template)}
                            className={buttonClass()}
                        >
                            Restaurar plantilla
                        </button>
                        <span className="ml-auto font-mono text-[11px] text-faint">
                            {form.data.rules.length.toLocaleString('es-ES')} / {max_length.toLocaleString('es-ES')}
                        </span>
                    </div>
                </div>

                <aside className="h-fit rounded-2xl border border-line bg-surface p-4 text-[13px]">
                    <h2 className="mb-3 text-[12px] font-medium tracking-wide text-muted uppercase">Lo que entiendo</h2>
                    <dl className="space-y-3">
                        <div>
                            <dt className="text-[12px] text-muted">Proyectos (vigilo menciones aquí)</dt>
                            <dd className="mt-0.5 flex flex-wrap gap-1">
                                {understood.projects.length > 0 ? (
                                    understood.projects.map((key) => (
                                        <span key={key} className="rounded-md bg-soft px-1.5 py-0.5 font-mono text-[12px]">
                                            {key}
                                        </span>
                                    ))
                                ) : (
                                    <span className="text-warn">Ninguno: vigilaría todos. Añade «- Proyectos: CPD».</span>
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[12px] text-muted">JQL de partida</dt>
                            <dd className="mt-0.5 font-mono text-[12px] break-words">{understood.jql ?? 'Mis issues abiertos'}</dd>
                        </div>
                        <div>
                            <dt className="text-[12px] text-muted">Componentes de cada issue nuevo</dt>
                            <dd className="mt-0.5 font-mono text-[12px]">{understood.components.join(', ') || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-[12px] text-muted">Enlace de dependencias</dt>
                            <dd className="mt-0.5 font-mono text-[12px]">{understood.link_type}</dd>
                        </div>
                        <div>
                            <dt className="text-[12px] text-muted">Secciones</dt>
                            <dd className="mt-0.5 text-[12.5px]">{understood.sections.join(' · ') || '—'}</dd>
                        </div>
                    </dl>
                    <p className="mt-4 text-[12px] text-faint">Se actualiza al guardar. Todo lo demás lo lee el asistente tal cual: escribe como se lo explicarías a alguien nuevo en el equipo.</p>
                </aside>
            </form>
        </SettingsLayout>
    );
}
