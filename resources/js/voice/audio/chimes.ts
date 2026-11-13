/**
 * Interface sounds, synthesised with Web Audio (no audio files).
 * The phone's silent switch, when it applies, simply mutes them.
 */

let taps: AudioContext | null = null;

function tapContext(): AudioContext | null {
    try {
        taps ??= new AudioContext();
        void taps.resume();

        return taps;
    } catch {
        return null;
    }
}

export type TapKind = 'talk' | 'stop' | 'mute' | 'unmute';

/**
 * A soft tone: sine through a low-pass, with an exponential envelope so it
 * does not click when it ends. Pitch moves, which is what makes each action
 * readable.
 */
function tone(
    context: AudioContext,
    { from, to, duration, volume, delay = 0, lowpass = 2400 }: { from: number; to: number; duration: number; volume: number; delay?: number; lowpass?: number },
): void {
    const start = context.currentTime + delay;
    const oscillator = context.createOscillator();
    const filter = context.createBiquadFilter();
    const gain = context.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(from, start);
    oscillator.frequency.exponentialRampToValueAtTime(to, start + duration * 0.72);
    filter.type = 'lowpass';
    filter.frequency.setValueAtTime(lowpass, start);
    filter.Q.value = 0.6;
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + Math.min(0.018, duration * 0.25));
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    oscillator.connect(filter).connect(gain).connect(context.destination);
    oscillator.start(start);
    oscillator.stop(start + duration + 0.03);
}

/** A short, rounded tick: noise through a gentle band-pass, not raw hiss. */
function tick(context: AudioContext, { frequency, duration, volume }: { frequency: number; duration: number; volume: number }): void {
    const frames = Math.max(1, Math.floor(context.sampleRate * duration));
    const buffer = context.createBuffer(1, frames, context.sampleRate);
    const samples = buffer.getChannelData(0);

    for (let i = 0; i < frames; i++) {
        samples[i] = (Math.random() * 2 - 1) * Math.exp(-i / (frames * 0.22));
    }

    const start = context.currentTime;
    const source = context.createBufferSource();
    const filter = context.createBiquadFilter();
    const gain = context.createGain();
    source.buffer = buffer;
    filter.type = 'bandpass';
    filter.frequency.value = frequency;
    filter.Q.value = 0.8;
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + 0.004);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    source.connect(filter).connect(gain).connect(context.destination);
    source.start(start);
}

/**
 * One sound per action. Talk opens upward, stop falls and settles, mute is
 * a closed tick, unmute is a short rising pop.
 */
export function playTap(kind: TapKind): void {
    const context = tapContext();

    if (!context) {
        return;
    }

    if (kind === 'talk') {
        tone(context, { from: 523.25, to: 783.99, duration: 0.22, volume: 0.09, lowpass: 3200 });
        tone(context, { from: 659.25, to: 987.77, duration: 0.28, volume: 0.045, delay: 0.05, lowpass: 2800 });

        return;
    }

    if (kind === 'stop') {
        tone(context, { from: 392, to: 196, duration: 0.28, volume: 0.1, lowpass: 1400 });

        return;
    }

    if (kind === 'mute') {
        tick(context, { frequency: 900, duration: 0.055, volume: 0.12 });

        return;
    }

    tone(context, { from: 440, to: 659.25, duration: 0.11, volume: 0.08, lowpass: 2600 });
}

/** E major chord, spread over two octaves. */
const HALO_NOTES = [659.26, 987.77, 1318.51, 1975.53];
const HALO_DETUNE_CENTS = 6;

/**
 * Soft, airy "halo" played when a conversation connects: the notes of a
 * major chord fade in one after another and ring out slowly. Each note has
 * two slightly detuned voices so it shimmers.
 */
export function playHalo(context: AudioContext): void {
    const start = context.currentTime + 0.02;
    const master = context.createGain();
    master.gain.value = 0.18;
    master.connect(context.destination);

    HALO_NOTES.forEach((frequency, index) => {
        const noteStart = start + index * 0.07;
        const peak = 0.32 / (index + 1.5);
        const envelope = context.createGain();

        envelope.gain.setValueAtTime(0.0001, noteStart);
        envelope.gain.exponentialRampToValueAtTime(peak, noteStart + 0.14);
        envelope.gain.exponentialRampToValueAtTime(0.0001, noteStart + 1.8);
        envelope.connect(master);

        for (const detune of [-HALO_DETUNE_CENTS, HALO_DETUNE_CENTS]) {
            const voice = context.createOscillator();
            voice.type = 'sine';
            voice.frequency.value = frequency;
            voice.detune.value = detune;
            voice.connect(envelope);
            voice.start(noteStart);
            voice.stop(noteStart + 1.9);
        }
    });

    window.setTimeout(() => master.disconnect(), 2500);
}
