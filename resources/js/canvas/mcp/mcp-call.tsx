import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

export type McpCallProps = {
    action: ActionState;
    server: string;
    tool: string;
    description: string;
    arguments: Record<string, unknown>;
};

/**
 * A call to an MCP tool that may change something on that server: what it
 * will do, with which arguments, and the "sí" it waits for.
 */
const McpCall: CanvasComponent<McpCallProps> = ({ index, action, server, tool, description, arguments: args }) => (
    <CanvasFrame index={index} title={tool} source={`MCP · ${server}`}>
        {description && <p className="mb-3 text-[13.5px] text-muted">{description}</p>}
        <pre className="max-h-72 overflow-auto rounded-xl border border-dashed border-accent bg-accent-soft/40 p-4 font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap">
            {Object.keys(args ?? {}).length === 0 ? 'Sin argumentos.' : JSON.stringify(args, null, 2)}
        </pre>
        <ActionBar initial={action} doneLabel="Hecho en el servidor MCP" verb="ejecutar" countdownLabel="Se ejecutará" />
    </CanvasFrame>
);

export default McpCall;
