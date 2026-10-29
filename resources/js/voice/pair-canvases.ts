import type { CanvasDirective } from '@/canvas/types';
import type { ToolOutcome } from '@/voice/run-tool';

/**
 * What one turn puts on screen. A tool that brings its own companion (a draft
 * and the thread, a clash and the day) wins. Otherwise the last two canvases
 * of the turn stay, so a document and a Slack thread opened together are both visible.
 */
export function canvasesToShow(outcomes: ToolOutcome[]): CanvasDirective[] {
    const paired = outcomes.find((outcome) => outcome.canvas && outcome.beside);

    if (paired?.canvas && paired.beside) {
        return [paired.canvas, paired.beside];
    }

    return outcomes.flatMap((outcome) => (outcome.canvas ? [outcome.canvas] : [])).slice(-2);
}
