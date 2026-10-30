import { createContext } from 'react';
import type { ReactNode } from 'react';

import type { CanvasDirective } from '@/canvas/types';

/** A canvas drawn inside a composed view, beside others: a lighter frame, no number. */
export const EmbeddedContext = createContext(false);

/**
 * Draws a canvas inside a composed view. The Canvas renderer provides it, so
 * the OpenUI blocks can place a detail without importing every canvas.
 */
export const CanvasRendererContext = createContext<((directive: CanvasDirective) => ReactNode) | null>(null);
