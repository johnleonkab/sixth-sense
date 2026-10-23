import { useState } from 'react';

import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { HttpError } from '@/lib/http';

type SenseFileProps = {
    path: string;
    content: string;
    removing?: boolean;
    action: ActionState;
};

/**
 * A file about to be saved or deleted in the work archive. Markdown can be
 * rewritten here; saving that text confirms it, without saying "sí".
 */
const SenseFile: CanvasComponent<SenseFileProps> = ({ index, path, content, removing, action }) => {
    const editable = !removing && path.endsWith('.md');
    const { actions } = useCanvas();
    const state = actions.actions[action.id] ?? action;
    const [draft, setDraft] = useState(content);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const changed = draft !== content;

    const save = () => {
        setSaving(true);
        setError(null);

        void actions.override(action.id, draft)
            .catch((caught: unknown) => {
                setError(caught instanceof HttpError ? caught.message : 'No he podido guardar.');
            })
            .finally(() => setSaving(false));
    };

    return (
        <CanvasFrame index={index} title={state.label} source={`github · ${path}`}>
            {editable ? (
                <>
                    <textarea
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        readOnly={state.status !== 'pending' || saving}
                        spellCheck={false}
                        aria-label={`Editar ${path}`}
                        className="max-h-96 min-h-48 w-full resize-y rounded-xl border border-dashed border-accent bg-accent-soft/40 p-4 font-mono text-[13px] leading-relaxed text-ink outline-none focus:border-accent read-only:opacity-80"
                    />
                    {error && state.status === 'pending' && <p className="mt-2 text-[13px] text-crit">{error}</p>}
                </>
            ) : (
                <pre className="max-h-80 overflow-auto rounded-xl border border-dashed border-accent bg-accent-soft/40 p-4 text-[13px] leading-relaxed whitespace-pre-wrap">
                    {removing ? 'Se borrará este archivo.' : content || 'Archivo vacío.'}
                </pre>
            )}

            <ActionBar
                initial={action}
                doneLabel={removing ? 'Archivo borrado' : 'Guardado en el archivo'}
                linkLabel="Ver en GitHub"
                verb={removing ? 'borrar' : 'guardar'}
                countdownLabel={removing ? 'Se borrará' : 'Se guardará'}
                confirmLabel={editable ? (changed ? 'Guardar mi versión' : 'Guardar') : undefined}
                onConfirm={editable ? save : undefined}
                busy={saving}
                hint={editable && changed ? '«sí» por voz guardaría la propuesta original' : undefined}
            />
        </CanvasFrame>
    );
};

export default SenseFile;
