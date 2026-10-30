import type { CanvasDirective } from '@/canvas/types';
import { liveItems } from '@/canvas/openui/refs';
import { viewerStatus } from '@/canvas/viewers';

/** Long content (a document's blocks, an email's body) is left out: the ids are enough to open it again. */
const LEFT_OUT = new Set(['blocks', 'body', 'html', 'segments', 'runs', 'action', 'avatar_url', 'src']);

const MAX_PER_CANVAS = 1500;

/** A composed view is sent whole (up to this), so the assistant can change it instead of asking again. */
const MAX_PROGRAM = 3000;

/**
 * A composed view: its program, and what each numbered item is, so "abre el 2"
 * and "quita los leídos" both work from what is on screen.
 */
function describeView(props: Record<string, unknown>, index: number): string {
    // A list that queries its rows says what it shows now; otherwise, what Laravel listed.
    const items = liveItems(index) ?? (Array.isArray(props.items) ? (props.items as { n: number; title: string; args?: unknown }[]) : []);

    return JSON.stringify({
        title: props.title,
        source: props.source,
        items: items.slice(0, 20).map((item) => ({ n: item.n, title: item.title, ...(item.args && typeof item.args === 'object' ? item.args : {}) })),
        program: String(props.ui ?? '').slice(0, MAX_PROGRAM),
    });
}

function compact(value: unknown, depth: number): unknown {
    if (typeof value === 'string') {
        return value.length > 120 ? `${value.slice(0, 120)}…` : value;
    }

    if (Array.isArray(value)) {
        return depth > 2 ? `[${value.length}]` : value.slice(0, 8).map((item) => compact(item, depth + 1));
    }

    if (value && typeof value === 'object') {
        if (depth > 2) {
            return '{…}';
        }

        return Object.fromEntries(
            Object.entries(value)
                .filter(([key, item]) => !LEFT_OUT.has(key) && item !== null && item !== '' && item !== false)
                .map(([key, item]) => [key, compact(item, depth + 1)]),
        );
    }

    return value;
}

/**
 * What is on screen, numbered as the person sees it, for the assistant to
 * pick up where it was: each canvas, its key data and ids, and where a
 * viewer is (page, zoom).
 */
export function describeCanvases(directives: CanvasDirective[]): string | null {
    if (directives.length === 0) {
        return null;
    }

    return directives
        .map((directive, position) => {
            const status = viewerStatus(position + 1);
            const props = directive.view === 'openui.view' ? describeView(directive.props, position + 1) : JSON.stringify(compact(directive.props, 0)).slice(0, MAX_PER_CANVAS);

            return `${position + 1}. ${directive.view}${status ? ` (${status})` : ''}: ${props}`;
        })
        .join('\n');
}
