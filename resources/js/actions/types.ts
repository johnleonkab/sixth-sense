/** Seconds to undo a confirmed action; the same as Actions::UNDO_WINDOW in Laravel. */
export const UNDO_SECONDS = 6;

export type ActionStatus = 'pending' | 'confirmed' | 'executing' | 'executed' | 'cancelled' | 'undone' | 'failed' | 'expired';

/** A change the assistant proposed; mirrors PendingAction::toState(). */
export type ActionState = {
    id: number;
    type: string;
    label: string;
    status: ActionStatus;
    expires_at: string;
    undo_until: string | null;
    result: Record<string, unknown> | null;
    error: string | null;
};

export const isFinished = (status: ActionStatus): boolean =>
    ['executed', 'cancelled', 'undone', 'failed', 'expired'].includes(status);
