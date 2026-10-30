import { router } from '@inertiajs/react';

import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';
import { Button } from '@/components/button';

export type AutonomyLevelRow = {
    action: 'reply' | 'redirect' | 'task';
    label: string;
    level: 'watch' | 'draft' | 'act';
    level_label: string;
    can_lower: boolean;
    lower_level: 'watch' | 'draft';
    can_raise: boolean;
    raise_reason: string | null;
};

export type AutonomyBoard = {
    levels: AutonomyLevelRow[];
    board: { label: string; value: string }[];
    action?: ActionState | null;
};

/**
 * The ladder and the readings. Raising waits for "sí". Lowering applies at once.
 */
const SlackAutonomyBoard: CanvasComponent<AutonomyBoard> = ({ index, levels, board, action }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title="Autonomía de Slack" source="Slack">
            <AutonomyLevels
                levels={levels}
                onLower={(actionName, level) => runTool('lower_slack_autonomy', { action: actionName, level })}
                onRaise={(actionName) => runTool('propose_slack_autonomy', { action: actionName })}
            />
            <AutonomyNumbers board={board} />
            {action && <ActionBar initial={action} doneLabel="Nivel subido" verb="subir" />}
        </CanvasFrame>
    );
};

export function AutonomyLevels({
    levels,
    onLower,
    onRaise,
}: {
    levels: AutonomyLevelRow[];
    onLower?: (action: AutonomyLevelRow['action'], level: AutonomyLevelRow['lower_level']) => void;
    onRaise?: (action: AutonomyLevelRow['action']) => void;
}) {
    const lower = onLower ?? ((action: AutonomyLevelRow['action'], level: AutonomyLevelRow['lower_level']) => router.post('/settings/slack-autonomy/lower', { action, level }, { preserveScroll: true }));
    const raise = onRaise ?? ((action: AutonomyLevelRow['action']) => router.post('/settings/slack-autonomy/promote', { action }, { preserveScroll: true }));

    return (
        <ul className="divide-y divide-line">
            {levels.map((row) => (
                <li key={row.action} className="flex flex-wrap items-center gap-2 py-3">
                    <span className="min-w-0 flex-1 text-[14px]">{row.label}</span>
                    <span className="rounded-full bg-soft px-2.5 py-1 text-[12px] text-muted">{row.level_label}</span>
                    {row.can_lower && (
                        <Button size="extra-small" onClick={() => lower(row.action, row.lower_level)}>
                            Bajar
                        </Button>
                    )}
                    {row.can_raise && (
                        <Button size="extra-small" onClick={() => raise(row.action)}>
                            Subir
                        </Button>
                    )}
                    {row.raise_reason && <p className="basis-full text-[12px] text-muted">{row.raise_reason}</p>}
                </li>
            ))}
        </ul>
    );
}

export function AutonomyNumbers({ board }: { board: AutonomyBoard['board'] }) {
    return (
        <dl className="mt-2 grid gap-2 sm:grid-cols-2">
            {board.map((item) => (
                <div key={item.label} className="rounded-xl bg-soft px-3 py-2">
                    <dt className="text-[12px] text-muted">{item.label}</dt>
                    <dd className="text-[14px]">{item.value}</dd>
                </div>
            ))}
        </dl>
    );
}

export default SlackAutonomyBoard;
