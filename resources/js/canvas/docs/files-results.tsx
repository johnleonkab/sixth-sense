import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

type FileRow = {
    number: number;
    ref: string;
    title: string;
    kind: 'doc' | 'sheet' | 'slide' | 'notion';
    source: string;
    account: string;
};

type FilesResultsProps = {
    title: string;
    widened: boolean;
    queries: string[];
    files: FileRow[];
};

const kindLabel: Record<FileRow['kind'], string> = {
    doc: 'Doc',
    sheet: 'Hoja',
    slide: 'Slides',
    notion: 'Notion',
};

/**
 * Files found in Drive and Notion, numbered so the person can say "abre el 2".
 */
const FilesResults: CanvasComponent<FilesResultsProps> = ({ index, title, widened, queries, files }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title={title} source="Drive · Notion">
            <p className="mb-3 text-[12.5px] text-muted">
                {widened ? 'No estaba en la búsqueda. Estos son los últimos, y arriba los que coinciden por nombre.' : queries.map((query) => `«${query}»`).join(' · ')}
            </p>
            {files.length === 0 ? (
                <p className="text-[13px] text-muted">No hay archivos que coincidan.</p>
            ) : (
                <ul className="divide-y divide-line">
                    {files.map((file) => (
                        <li key={`${file.source}-${file.ref}`}>
                            <button
                                type="button"
                                onClick={() => runTool(file.kind === 'notion' ? 'open_notion_page' : 'open_document', file.kind === 'notion' ? { page: file.ref } : { document: file.ref })}
                                className="flex w-full items-center gap-3 py-2.5 text-left hover:bg-soft"
                            >
                                <span className="grid size-[22px] flex-none place-items-center rounded-full border border-line font-mono text-[11px] text-muted">{file.number}</span>
                                <span className="w-14 flex-none text-[11px] text-faint">{kindLabel[file.kind]}</span>
                                <span className="min-w-0 flex-1 truncate text-[14px] font-medium">{file.title}</span>
                                <span className="hidden truncate text-[12px] text-muted sm:block">{file.account}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </CanvasFrame>
    );
};

export default FilesResults;
