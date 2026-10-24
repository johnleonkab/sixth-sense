import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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

type Props = {
    repository: Repository;
    files: string[];
    error: string | null;
    document: Document | null;
};

/**
 * The archive as a file editor: a tree of markdown on main, and one live
 * preview surface for the open document.
 */
export default function ArchiveFiles({ repository, files, error, document }: Props) {
    const pageError = (usePage().props as { errors?: Record<string, string> }).errors?.contents;
    const [dirty, setDirty] = useState(false);

    return (
        <SettingsLayout editor title="Archivos" description={`${document?.branch ?? 'main'} · ${repository.full_name}`}>
            <ArchiveNav />

            {(error || pageError) && <p className="mb-3 shrink-0 text-[13px] text-crit">{error || pageError}</p>}

            {!repository.writable && (
                <p className="mb-3 shrink-0 text-[13px] text-warn">Este archivo es de solo lectura. Permite actualizar su código para guardar en main.</p>
            )}

            <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-line bg-surface md:flex-row">
                <FileTree files={files} selected={document?.path ?? null} dirty={dirty} repository={repository.full_name} />
                {document ? (
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
