import { playTap, type TapKind } from '@/voice/audio/chimes';

export type { TapKind };

const pulses: Record<TapKind, number | number[]> = {
    talk: 14,
    stop: [16, 32, 22],
    mute: 8,
    unmute: [6, 18, 10],
};

/**
 * Press feedback for the voice buttons: a click if the phone is in sound
 * mode, and a vibration when the OS allows it.
 */
export function press(kind: TapKind): void {
    playTap(kind);

    try {
        navigator.vibrate?.(pulses[kind]);
    } catch {
        // iOS Safari has no vibrate; Android may refuse it outside a gesture.
    }
}
