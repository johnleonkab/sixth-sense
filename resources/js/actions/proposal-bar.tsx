import { useEffect, useState } from 'react';

import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';
import { getJson } from '@/lib/http';

/**
 * The bar of a proposed change known only by its id: Sí and Cancelar, then
 * the seconds to undo. Its state comes from Laravel, so nothing on screen
 * can make one up.
 */
export default function ProposalBar({ action, verb = 'aplicar', done = 'Hecho' }: { action: number; verb?: string; done?: string }) {
    const { actions } = useCanvas();
    const [state, setState] = useState<ActionState | null>(actions.actions[action] ?? null);
    const [missing, setMissing] = useState(false);

    useEffect(() => {
        if (state === null) {
            getJson<ActionState>(`/actions/${action}`).then(setState, () => setMissing(true));
        }
    }, [action]); // eslint-disable-line react-hooks/exhaustive-deps

    if (missing) {
        return <p className="text-[12.5px] text-muted">Esta propuesta ya no existe.</p>;
    }

    if (state === null) {
        return <div className="alerts-shimmer h-9 w-48 rounded-full" />;
    }

    return <ActionBar initial={state} verb={verb} doneLabel={done} countdownLabel={verb === 'enviar' ? undefined : `Se va a ${verb}`} />;
}
