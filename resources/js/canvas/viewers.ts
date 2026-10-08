/**
 * Viewers on screen (a PDF, an image) that the assistant controls with
 * control_viewer. The call is answered here, in the browser, because the
 * open file, its pages and its text are here.
 */
export type ViewerControl = (args: Record<string, unknown>) => Promise<Record<string, unknown>>;

const viewers = new Map<number, ViewerControl>();

const statuses = new Map<number, () => string>();

/**
 * Register the viewer shown as canvas number `index`, and optionally how to
 * say where it is ("página 2 de 5"). Returns the unregister function.
 */
export function registerViewer(index: number, control: ViewerControl, status?: () => string): () => void {
    viewers.set(index, control);

    if (status) {
        statuses.set(index, status);
    }

    return () => {
        if (viewers.get(index) === control) {
            viewers.delete(index);
            statuses.delete(index);
        }
    };
}

/** Where the viewer number `index` is, if it says. */
export function viewerStatus(index: number): string | null {
    return statuses.get(index)?.() ?? null;
}

/**
 * Answer a control_viewer call, or null when no viewer is on screen (Laravel
 * then answers that there is nothing open).
 */
export async function controlViewer(args: Record<string, unknown>): Promise<Record<string, unknown> | null> {
    const wanted = typeof args.canvas === 'number' ? viewers.get(args.canvas) : undefined;
    const control = wanted ?? [...viewers.values()].at(-1);

    if (!control) {
        return null;
    }

    try {
        return await control(args);
    } catch (error) {
        console.error(error);

        return { error: 'No he podido hacerlo en el visor.' };
    }
}
