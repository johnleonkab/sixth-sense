import { Renderer } from '@openuidev/react-lang';
import type { ActionEvent } from '@openuidev/react-lang';

import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { openUiLibrary } from '@/canvas/openui/library';
import type { CanvasComponent } from '@/canvas/types';

export type OpenUiViewProps = {
    title: string;
    /** Where the data comes from, for the frame ("Google Analytics", an MCP server…). */
    source: string;
    /** The view, in OpenUI Lang. */
    ui: string;
};

/**
 * A view the assistant composed from the OpenUI blocks, for data no fixed
 * canvas covers. Unknown blocks are dropped instead of breaking the screen.
 * A button either asks the assistant something or opens an https link.
 */
const OpenUiView: CanvasComponent<OpenUiViewProps> = ({ index, title, source, ui }) => {
    const { tell } = useCanvas();

    const act = (event: ActionEvent) => {
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
