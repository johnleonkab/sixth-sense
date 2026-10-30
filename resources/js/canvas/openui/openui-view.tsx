import { Renderer } from '@openuidev/react-lang';
import type { ActionEvent } from '@openuidev/react-lang';

import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { openUiLibrary } from '@/canvas/openui/library';
import { datasetNames } from '@/canvas/openui/spec';
import type { CanvasComponent } from '@/canvas/types';
import { browserTimezone, postJson } from '@/lib/http';

/** How to open the item numbered n of the view's lists: a tool and its arguments. */
export type OpenableItem = { n: number; title: string; tool: string; args: Record<string, unknown> };

export type OpenUiViewProps = {
    title: string;
    /** Where the data comes from, for the frame ("Google Analytics", an MCP server…). */
    source: string;
    /** The view, in OpenUI Lang. */
    ui: string;
    items?: OpenableItem[];
    /** A saved view: its name and when its data was taken. */
    saved_as?: string;
    saved_at?: string;
};

/**
 * A view composed from the OpenUI blocks, written by Laravel or changed by
 * the assistant. Unknown blocks are dropped instead of breaking the screen.
 * A button asks the assistant something or opens an https link; an item of
 * a list opens with the tool Laravel said opens it, without the model.
 */
/**
 * Query() runs these in the browser: each asks Laravel for the rows of one
 * read-only dataset, with the signed-in person's access. Nothing else can be
 * called from a view.
 */
const toolProvider = Object.fromEntries(
    datasetNames.map((name) => [name, (args: Record<string, unknown>) => postJson(`/canvas/query/${encodeURIComponent(name)}`, { arguments: args, timezone: browserTimezone() })]),
);

const savedDate = (iso: string) => new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));

const OpenUiView: CanvasComponent<OpenUiViewProps> = ({ index, title, source, ui, items, saved_as: savedAs, saved_at: savedAt }) => {
    const { tell, runTool } = useCanvas();

    const act = (event: ActionEvent) => {
        if (event.type === 'OpenItem') {
            const item = items?.find((candidate) => candidate.n === Number(event.params.n));

            if (item) {
                runTool(item.tool, item.args);
            } else {
                tell?.(`Abre el ${String(event.params.n)}`);
            }

            return;
        }

        if (event.type === 'open_url') {
            const url = String(event.params.url ?? '');

            if (/^https:\/\//i.test(url)) {
                window.open(url, '_blank', 'noopener,noreferrer');
            }

            return;
        }

        const message = String(event.params.message ?? event.humanFriendlyMessage ?? '').trim();

        if (message !== '') {
            tell?.(message);
        }
    };

    return (
        <CanvasFrame index={index} title={title} source={source}>
            {savedAs && (
                <p className="-mt-1 mb-3 text-[12px] text-faint">
                    Guardada como «{savedAs}»{savedAt && ` · datos del ${savedDate(savedAt)}`}
                </p>
            )}
            <Renderer response={ui} library={openUiLibrary} isStreaming={false} onAction={act} toolProvider={toolProvider} />
        </CanvasFrame>
    );
};

export default OpenUiView;
