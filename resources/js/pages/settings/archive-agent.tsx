import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import SettingsLayout from '@/layouts/settings-layout';
import ArchiveNav from '@/pages/settings/archive-nav';

type Field = {
    key: string;
    label: string;
    value: string;
};

type Props = {
    repository: { full_name: string; writable: boolean };
    slug: string | null;
    fields: Field[];
    body: string;
    readable: boolean;
};

const fieldClass = 'w-full rounded-xl border border-line bg-surface px-3 py-2 text-[14px] text-ink outline-none focus:border-accent';

/**
 * Create or update one agent. Fields come from types/agent.yml; the body is markdown.
 */
export default function ArchiveAgent({ repository, slug, fields, body, readable }: Props) {
    const creating = slug === null;
    const agentError = (usePage().props as { errors?: Record<string, string> }).errors?.agent;
    const form = useForm({
        slug: slug ?? '',
        fields: Object.fromEntries(fields.map((field) => [field.key, field.value])),
        body,
    });
    const submitLabel = form.processing ? 'Guardando…' : creating ? 'Crear agente' : 'Guardar';

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (creating) {
            form.post('/settings/archive/agents');

            return;
        }

        form.put(`/settings/archive/agents/${slug}`);
    };

    return (
        <SettingsLayout
            title={creating ? 'Nuevo agente' : (fields.find((field) => field.key === 'name')?.value ?? slug)}
            description={creating ? `Se guardará en ${repository.full_name} como agents/carpeta/agent.md.` : `agents/${slug}/agent.md en ${repository.full_name}.`}
        >
            <ArchiveNav />

            <Link href="/settings/archive/agents" className="mb-4 inline-flex text-[12.5px] text-muted hover:text-ink">
                ← Agentes
            </Link>

            {!readable && (
                <p className="mb-4 text-[13px] text-warn">La cabecera no se puede leer. Al guardar se reescribe con los campos de types/agent.yml.</p>
            )}

            {!repository.writable && <p className="mb-4 text-[13px] text-warn">Este archivo es de solo lectura, así que no se puede guardar.</p>}

            <form onSubmit={submit} className="grid gap-4">
                {creating && (
                    <label className="grid gap-1.5">
                        <span className="text-[13px] font-medium">Carpeta</span>
                        <span className="text-[12.5px] text-muted">Minúsculas, números y guiones. Queda en la ruta y no se cambia después.</span>
                        <input
                            value={form.data.slug}
                            onChange={(event) => form.setData('slug', event.target.value)}
                            spellCheck={false}
                            autoCapitalize="none"
                            className={`${fieldClass} font-mono`}
                            placeholder="cobros"
                            required
                        />
                        {form.errors.slug && <span className="text-[12.5px] text-crit">{form.errors.slug}</span>}
                    </label>
                )}

                {fields.map((field) => (
                    <label key={field.key} className="grid gap-1.5">
                        <span className="text-[13px] font-medium">{field.label}</span>
                        <span className="font-mono text-[11px] text-faint">{field.key}</span>
                        {field.key === 'name' ? (
                            <input
                                value={form.data.fields[field.key] ?? ''}
                                onChange={(event) => form.setData('fields', { ...form.data.fields, [field.key]: event.target.value })}
                                className={fieldClass}
                                required
                            />
                        ) : (
                            <textarea
                                value={form.data.fields[field.key] ?? ''}
                                onChange={(event) => form.setData('fields', { ...form.data.fields, [field.key]: event.target.value })}
                                rows={3}
                                className={`${fieldClass} resize-y`}
                                required
                            />
                        )}
                        {form.errors[`fields.${field.key}`] && <span className="text-[12.5px] text-crit">{form.errors[`fields.${field.key}`]}</span>}
                    </label>
                ))}

                <label className="grid gap-1.5">
                    <span className="text-[13px] font-medium">Markdown</span>
                    <span className="text-[12.5px] text-muted">Lo que el agente sigue cuando está en uso. Va debajo de la cabecera.</span>
                    <textarea
                        value={form.data.body}
                        onChange={(event) => form.setData('body', event.target.value)}
                        spellCheck
                        rows={14}
                        className={`${fieldClass} resize-y font-mono text-[13px] leading-[1.6]`}
                        aria-label="Instrucciones en markdown"
                    />
                    {form.errors.body && <span className="text-[12.5px] text-crit">{form.errors.body}</span>}
                </label>

                {agentError && <p className="text-[13px] text-crit">{agentError}</p>}

                <div>
                    <button
                        type="submit"
                        disabled={form.processing || !repository.writable}
                        className="rounded-full bg-ink px-4 py-1.5 text-[13px] font-medium text-bg hover:opacity-90 disabled:opacity-40"
                    >
                        {submitLabel}
                    </button>
                </div>
            </form>
        </SettingsLayout>
    );
}
