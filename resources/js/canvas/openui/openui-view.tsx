import { Renderer } from '@openuidev/react-lang';
import type { ActionEvent } from '@openuidev/react-lang';

import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { openUiLibrary } from '@/canvas/openui/library';
import type { CanvasComponent } from '@/canvas/types';

/** How to open the item numbered n of the view's lists: a tool and its arguments. */
export type OpenableItem = { n: number; title: string; tool: string; args: Record<string, unknown> };

export type OpenUiViewProps = {
    title: string;
    /** Where the data comes from, for the frame ("Google Analytics", an MCP server…). */
    source: string;
    /** The view, in OpenUI Lang. */
    ui: string;
    items?: OpenableItem[];
};

/**
 * A view composed from the OpenUI blocks, written by Laravel or changed by
 * the assistant. Unknown blocks are dropped instead of breaking the screen.
 * A button asks the assistant something or opens an https link; an item of
 * a list opens with the tool Laravel said opens it, without the model.
 */
const OpenUiView: CanvasComponent<OpenUiViewProps> = ({ index, title, source, ui, items }) => {
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
            <Renderer response={ui} library={openUiLibrary} isStreaming={false} onAction={act} />
        </CanvasFrame>
    );
};

export default OpenUiView;
