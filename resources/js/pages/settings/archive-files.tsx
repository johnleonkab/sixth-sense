import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';

import LiveMarkdown from '@/components/live-markdown';
import SettingsLayout from '@/layouts/settings-layout';
import ArchiveNav from '@/pages/settings/archive-nav';
import FileTree from '@/pages/settings/file-tree';

type Repository = {
    full_name: string;
    writable: boolean;
};

type Document = {
    path: string;
    contents: string;
    branch: string;
};

type Field = {
    key: string;
    label: string;
};

type TypeSpec = {
    type: string;
    where: string;
    name: string;
    required: Field[];
    optional: Field[];
    slots: Array<'project' | 'slug' | 'date'>;
};

type Draft = {
    kind: 'file' | 'folder' | 'reference';
    type: string;
    project: string;
    slug: string;
    date: string;
    fields: Record<string, string>;
    body: string;
    folder: 'project' | 'agent';
    name: string;
    agent: string;
};

type Props = {
    repository: Repository;
    files: string[];
    error: string | null;
    document: Document | null;
    creating: boolean;
    types: TypeSpec[];
};

const fieldClass = 'w-full rounded-xl border border-line bg-bg px-3 py-2 text-[14px] text-ink outline-none focus:border-accent';

/**
 * The archive as a file editor: a tree of markdown on main, and one live
 * preview surface for the open document.
 */
export default function ArchiveFiles({ repository, files, error, document, creating, types }: Props) {
    const pageError = (usePage().props as { errors?: Record<string, string> }).errors?.contents;
    const [dirty, setDirty] = useState(false);
    const description = creating ? `Nuevo · main · ${repository.full_name}` : `${document?.branch ?? 'main'} · ${repository.full_name}`;

    return (
        <SettingsLayout editor title="Archivos" description={description}>
            <ArchiveNav />

            {(error || pageError) && !creating && <p className="mb-3 shrink-0 text-[13px] text-crit">{error || pageError}</p>}

            {!repository.writable && (
                <p className="mb-3 shrink-0 text-[13px] text-warn">Este archivo es de solo lectura. Permite actualizar su código para guardar en main.</p>
            )}

            <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-line bg-surface md:flex-row">
                <FileTree
                    files={files}
                    selected={document?.path ?? null}
                    dirty={dirty}
                    repository={repository.full_name}
                    creatable={repository.writable}
                    creating={creating}
                />
                {creating ? (
                    <CreateDocument types={types} files={files} repository={repository} onDirty={setDirty} />
                ) : document ? (
                    <OpenDocument key={document.path} document={document} repository={repository} onDirty={setDirty} />
                ) : (
                    <div className="grid min-h-64 flex-1 place-items-center px-6 text-center text-[13.5px] text-muted">Elige un documento del árbol.</div>
                )}
            </div>
        </SettingsLayout>
    );
}

function OpenDocument({ document, repository, onDirty }: { document: Document; repository: Repository; onDirty: (dirty: boolean) => void }) {
    const form = useForm({ contents: document.contents });
    const name = document.path.slice(document.path.lastIndexOf('/') + 1);

    useEffect(() => {
        onDirty(form.isDirty);

        return () => onDirty(false);
    }, [form.isDirty, onDirty]);

    const save = () => {
        if (!repository.writable || !form.isDirty || form.processing) {
            return;
        }

        form.put(`/settings/archive/files/${encodeURI(document.path)}`, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
            className="flex min-h-0 min-w-0 flex-1 flex-col"
        >
                <div className="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-2">
                    <div className="min-w-0">
                        <p className="truncate text-[13.5px] font-medium">{name}</p>
                        <p className="truncate font-mono text-[11px] text-faint">{document.path}</p>
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing || !repository.writable || !form.isDirty}
                        className="shrink-0 rounded-full bg-ink px-4 py-1.5 text-[13px] font-medium text-bg hover:opacity-90 disabled:opacity-40"
                    >
                        {form.processing ? 'Guardando…' : 'Guardar en main'}
                    </button>
                </div>
                {form.errors.contents && <p className="px-4 pt-2 text-[13px] text-crit">{form.errors.contents}</p>}
                <LiveMarkdown
                    value={form.data.contents}
                    onChange={(contents) => form.setData('contents', contents)}
                    readOnly={!repository.writable}
                    resetKey={document.path}
                    label={`Editar ${document.path}`}
                    onSave={save}
                />
        </form>
    );
}

const slotCopy: Record<'project' | 'slug' | 'date', { label: string; hint: string }> = {
    project: { label: 'Proyecto', hint: 'Minúsculas, números y guiones. Es la carpeta del proyecto.' },
    slug: { label: 'Nombre', hint: 'Minúsculas, números y guiones.' },
    date: { label: 'Fecha', hint: 'AAAA-MM-DD.' },
};

/**
 * A new document follows one type from types/*.yml. A new folder copies the
 * project or agent template. A reference is markdown in agents/{slug}/references/.
 * The path is not typed by hand.
 */
function CreateDocument({ types, files, repository, onDirty }: { types: TypeSpec[]; files: string[]; repository: Repository; onDirty: (dirty: boolean) => void }) {
    const errors = (usePage().props as { errors?: Record<string, string> }).errors ?? {};
    const first = types[0];
    const form = useForm<Draft>({
        kind: 'file',
        type: first?.type ?? '',
        project: '',
        slug: '',
        date: today(),
        fields: blankFields(first),
        body: '',
        folder: 'project',
        name: '',
        agent: '',
    });
    const spec = types.find((type) => type.type === form.data.type) ?? null;
    const path = spec ? located(spec, form.data) : null;
    const agents = agentSlugs(files);
    const title = form.data.kind === 'folder' ? 'Nueva carpeta' : form.data.kind === 'reference' ? 'Nueva referencia' : 'Nuevo documento';
    const subtitle = form.data.kind === 'file' ? (path ?? 'La ruta la marcan el tipo y el nombre.') : form.data.kind === 'reference' ? referencePath(form.data.agent, form.data.name) : folderPath(form.data.folder, form.data.name);

    useEffect(() => {
        onDirty(form.isDirty);

        return () => onDirty(false);
    }, [form.isDirty, onDirty]);

    const chooseType = (type: string) => {
        const next = types.find((item) => item.type === type);

        form.setData({
            ...form.data,
            type,
            fields: blankFields(next),
            body: '',
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!repository.writable || form.processing) {
            return;
        }

        form.post('/settings/archive/files');
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 min-w-0 flex-1 flex-col">
            <div className="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-2">
                <div className="min-w-0">
                    <p className="truncate text-[13.5px] font-medium">{title}</p>
                    <p className="truncate font-mono text-[11px] text-faint">{subtitle}</p>
                </div>
                <button
                    type="submit"
                    disabled={form.processing || !repository.writable || (form.data.kind === 'file' && spec === null) || (form.data.kind === 'reference' && agents.length === 0)}
                    className="shrink-0 rounded-full bg-ink px-4 py-1.5 text-[13px] font-medium text-bg hover:opacity-90 disabled:opacity-40"
                >
                    {form.processing ? 'Creando…' : 'Crear en main'}
                </button>
            </div>
            {errors.contents && <p className="px-4 pt-2 text-[13px] text-crit">{errors.contents}</p>}
            <div className="grid max-h-[46%] shrink-0 gap-3 overflow-auto border-b border-line px-4 py-3">
                <div className="flex w-fit gap-1 rounded-full bg-soft p-1">
                    <ModeButton current={form.data.kind === 'file'} onClick={() => form.setData('kind', 'file')}>
                        Documento
                    </ModeButton>
                    <ModeButton current={form.data.kind === 'folder'} onClick={() => form.setData('kind', 'folder')}>
                        Carpeta
                    </ModeButton>
                    <ModeButton current={form.data.kind === 'reference'} onClick={() => form.setData('kind', 'reference')}>
                        Referencia
                    </ModeButton>
                </div>
                {form.data.kind === 'file' ? (
                    spec === null ? (
                        <p className="text-[13px] text-muted">No hay tipos en types/. Sin un tipo no se puede crear un documento.</p>
                    ) : (
                        <>
                            <label className="grid gap-1.5">
                                <span className="text-[13px] font-medium">Tipo</span>
                                <select value={form.data.type} onChange={(event) => chooseType(event.target.value)} className={fieldClass}>
                                    {types.map((type) => (
                                        <option key={type.type} value={type.type}>
                                            {type.type}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {spec.slots.map((slot) => (
                                <label key={slot} className="grid gap-1.5">
                                    <span className="text-[13px] font-medium">{slotCopy[slot].label}</span>
                                    <span className="text-[12.5px] text-muted">{slotCopy[slot].hint}</span>
                                    <input
                                        value={form.data[slot]}
                                        onChange={(event) => form.setData(slot, event.target.value)}
                                        type={slot === 'date' ? 'date' : 'text'}
                                        spellCheck={false}
                                        autoCapitalize="none"
                                        className={`${fieldClass} font-mono`}
                                        required
                                    />
                                </label>
                            ))}
                            {[...spec.required, ...spec.optional].map((field) => (
                                <label key={field.key} className="grid gap-1.5">
                                    <span className="text-[13px] font-medium">
                                        {field.key}
                                        {spec.optional.some((item) => item.key === field.key) && <span className="ml-2 font-normal text-faint">opcional</span>}
                                    </span>
                                    <span className="text-[12.5px] text-muted">{field.label}</span>
                                    <input
                                        value={form.data.fields[field.key] ?? ''}
                                        onChange={(event) => form.setData('fields', { ...form.data.fields, [field.key]: event.target.value })}
                                        className={fieldClass}
                                        required={spec.required.some((item) => item.key === field.key)}
                                    />
                                </label>
                            ))}
                        </>
                    )
                ) : form.data.kind === 'folder' ? (
                    <>
                        <label className="grid gap-1.5">
                            <span className="text-[13px] font-medium">Qué carpeta</span>
                            <select value={form.data.folder} onChange={(event) => form.setData('folder', event.target.value === 'agent' ? 'agent' : 'project')} className={fieldClass}>
                                <option value="project">Proyecto, desde projects/_template</option>
                                <option value="agent">Agente, desde agents/_template</option>
                            </select>
                        </label>
                        <label className="grid gap-1.5">
                            <span className="text-[13px] font-medium">{form.data.folder === 'project' ? 'Proyecto' : 'Agente'}</span>
                            <span className="text-[12.5px] text-muted">Minúsculas, números y guiones. Queda en la ruta y no se cambia después.</span>
                            <input
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                spellCheck={false}
                                autoCapitalize="none"
                                className={`${fieldClass} font-mono`}
                                placeholder={form.data.folder === 'project' ? 'prepago' : 'cobros'}
                                required
                            />
                            {errors.name && <span className="text-[12.5px] text-crit">{errors.name}</span>}
                        </label>
                        <p className="text-[13px] text-muted">
                            {form.data.folder === 'project'
                                ? 'Se copian los markdown de projects/_template. Cada uno sigue su tipo.'
                                : 'Se copia agents/_template/agent.md. Después puedes editar name, purpose y when.'}
                        </p>
                    </>
                ) : form.data.kind === 'reference' ? (
                    agents.length === 0 ? (
                        <p className="text-[13px] text-muted">Primero crea un agente. Las referencias viven en agents/&#123;agente&#125;/references/.</p>
                    ) : (
                        <>
                            <label className="grid gap-1.5">
                                <span className="text-[13px] font-medium">Agente</span>
                                <select value={form.data.agent} onChange={(event) => form.setData('agent', event.target.value)} className={fieldClass} required>
                                    <option value="">Elige un agente</option>
                                    {agents.map((agent) => (
                                        <option key={agent} value={agent}>
                                            {agent}
                                        </option>
                                    ))}
                                </select>
                                {errors.agent && <span className="text-[12.5px] text-crit">{errors.agent}</span>}
                            </label>
                            <label className="grid gap-1.5">
                                <span className="text-[13px] font-medium">Nombre</span>
                                <span className="text-[12.5px] text-muted">Minúsculas, números y guiones. Puedes añadir varios archivos en la misma carpeta.</span>
                                <input
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    spellCheck={false}
                                    autoCapitalize="none"
                                    className={`${fieldClass} font-mono`}
                                    placeholder="contexto"
                                    required
                                />
                                {errors.name && <span className="text-[12.5px] text-crit">{errors.name}</span>}
                            </label>
                            {errors.body && <p className="text-[13px] text-crit">{errors.body}</p>}
                        </>
                    )
                ) : null}
            </div>
            {((form.data.kind === 'file' && spec !== null) || (form.data.kind === 'reference' && agents.length > 0)) && (
                <LiveMarkdown
                    value={form.data.body}
                    onChange={(body) => form.setData('body', body)}
                    readOnly={!repository.writable}
                    resetKey={form.data.kind === 'reference' ? `reference:${form.data.agent}` : form.data.type}
                    label={form.data.kind === 'reference' ? 'Escribir la referencia' : `Escribir un ${form.data.type}`}
                />
            )}
        </form>
    );
}

function ModeButton({ current, onClick, children }: { current: boolean; onClick: () => void; children: string }) {
    return (
        <button type="button" onClick={onClick} className={`rounded-full px-3 py-1 text-[13px] ${current ? 'bg-surface font-medium text-ink' : 'text-muted'}`} aria-pressed={current}>
            {children}
        </button>
    );
}

function blankFields(spec: TypeSpec | undefined): Record<string, string> {
    return Object.fromEntries([...(spec?.required ?? []), ...(spec?.optional ?? [])].map((field) => [field.key, '']));
}

function located(spec: TypeSpec, draft: Draft): string | null {
    const slots = { project: draft.project, slug: draft.slug, date: draft.date };
    const folder = fill(spec.where, slots);
    const name = fill(spec.name, slots);

    if (folder === null || name === null || name === '' || name.includes('/')) {
        return null;
    }

    return `${folder}/${name}`;
}

function fill(pattern: string, slots: Record<'project' | 'slug' | 'date', string>): string | null {
    let value = pattern;

    for (const slot of ['project', 'slug', 'date'] as const) {
        const token = `{${slot}}`;

        if (!value.includes(token)) {
            continue;
        }

        if (!slotOk(slot, slots[slot])) {
            return null;
        }

        value = value.replaceAll(token, slots[slot]);
    }

    return value.includes('{') ? null : value;
}

function slotOk(slot: 'project' | 'slug' | 'date', value: string): boolean {
    if (slot === 'date') {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return false;
        }

        const [year, month, day] = value.split('-').map(Number);
        const date = new Date(year, month - 1, day);

        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
    }

    return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value);
}

function agentSlugs(files: string[]): string[] {
    const slugs = new Set<string>();

    for (const file of files) {
        const match = /^agents\/([^/]+)\/agent\.md$/.exec(file);

        if (match && match[1] !== '_template') {
            slugs.add(match[1]);
        }
    }

    return [...slugs].sort();
}

function referencePath(agent: string, name: string): string {
    const folder = /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(agent) ? agent : '…';
    const file = /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name) ? name : '…';

    return `agents/${folder}/references/${file}.md`;
}

function folderPath(folder: 'project' | 'agent', name: string): string {
    const safe = /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name) ? name : '…';

    return folder === 'project' ? `projects/${safe}/` : `agents/${safe}/agent.md`;
}

function today(): string {
    const date = new Date();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}
