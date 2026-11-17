import { useState } from 'react';

import { Button } from '@/components/button';
import SnoozePicker from '@/intents/snooze-picker';
import type { Handoff } from '@/intents/types';

/** Delegate, snooze, allow next time, and undo. */
export default function ActionMenu({
    handoff,
    canAlways,
    canUndo,
    onDelegate,
    onSnooze,
    onAlways,
    onUndo,
    onNotForMe,
}: {
    handoff: Handoff[];
    canAlways: boolean;
    canUndo: boolean;
    onDelegate: (personId: number) => void;
    onSnooze: (until: string) => void;
    onAlways: () => void;
    onUndo: () => void;
    onNotForMe: () => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className="flex flex-col gap-2">
            <Button type="button" size="small" variant="tertiary" aria-expanded={open} onClick={() => setOpen((current) => !current)}>
                Acciones
            </Button>
            {open && (
                <div className="flex flex-col gap-2">
                    {handoff.map((person) => (
                        <Button key={person.id} type="button" size="small" onClick={() => onDelegate(person.id)}>
                            Encargar a {person.name}
                        </Button>
                    ))}
                    <SnoozePicker onSnooze={onSnooze} />
                    {canAlways && (
                        <Button type="button" size="small" onClick={onAlways}>
                            Hazlo siempre
                        </Button>
                    )}
                    {canUndo && (
                        <Button type="button" size="small" onClick={onUndo}>
                            Deshacer
                        </Button>
                    )}
                    <Button type="button" size="small" variant="tertiary" destructive onClick={onNotForMe}>
                        No es para mí
                    </Button>
                </div>
            )}
        </div>
    );
}
