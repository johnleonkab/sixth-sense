import type { CanvasDirective } from '@/canvas/types';
import { controlViewer } from '@/canvas/viewers';
import { browserTimezone, postJson } from '@/lib/http';
import type { ToolCall } from '@/voice/types';

export type ToolOutcome = {
    output: Record<string, unknown>;
    canvas: CanvasDirective | null;
    beside?: CanvasDirective | null;
};

/**
 * Run a tool call in Laravel, except what the screen itself answers (the
 * viewers). Failures are reported to the model as an error
 * output, so it can tell the person instead of waiting forever.
 */
export async function runTool(call: ToolCall): Promise<ToolOutcome> {
    if (call.name === 'control_viewer') {
        const output = await controlViewer(call.args);

        if (output) {
            return { output, canvas: null };
        }
    }

    try {
        return await postJson<ToolOutcome>(`/voice/tools/${encodeURIComponent(call.name)}`, {
            arguments: call.args,
            timezone: browserTimezone(),
        });
    } catch (error) {
        console.error(error);

        return { output: { error: 'La herramienta ha fallado. Dile al usuario que no has podido consultarlo.' }, canvas: null };
    }
}
