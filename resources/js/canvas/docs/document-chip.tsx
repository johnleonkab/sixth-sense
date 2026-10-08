import { useCanvas } from '@/canvas/canvas-context';

/**
 * A Google Doc linked from somewhere else (an email, an event). Opens it on screen.
 */
export default function DocumentChip({ url, title }: { url: string; title: string | null }) {
    const { runTool } = useCanvas();

    return (
        <button
            type="button"
            onClick={() => runTool('open_document', { document: url })}
            className="inline-flex max-w-[260px] items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 py-1 text-[12.5px] hover:bg-soft"
        >
            <span aria-hidden className="grid size-4 place-items-center rounded-[3px] bg-accent text-[9px] font-semibold text-accent-ink">
                D
            </span>
            <span className="truncate">{title ?? 'Documento de Google'}</span>
        </button>
    );
}
