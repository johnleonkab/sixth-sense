import { createContext, useContext } from 'react';

import type { ActionsStore } from '@/actions/use-actions';

export type CanvasContextValue = {
    /** Run a tool from the screen (e.g. clicking a linked document) and show its canvas. */
    runTool: (name: string, args: Record<string, unknown>) => void;
    /** Say something to the assistant from the screen (a button of a composed view); it answers. */
    tell?: (text: string) => void;
    actions: ActionsStore;
};

export const CanvasContext = createContext<CanvasContextValue | null>(null);

export function useCanvas(): CanvasContextValue {
    const value = useContext(CanvasContext);

    if (!value) {
        throw new Error('useCanvas must be used inside <CanvasContext>.');
    }

    return value;
}
