/**
 * Alert sounds per source, synthesised with Web Audio as in docs/prototype.html.
 * Browsers only allow sound after the person has interacted with the page,
 * so the audio context is unlocked on the first click or key press.
 */
let context: AudioContext | null = null;

function audio(): AudioContext | null {
    try {
        context ??= new AudioContext();
        void context.resume();

        return context;
    } catch {
        return null;
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('pointerdown', audio, { once: true });
    document.addEventListener('keydown', audio, { once: true });
}

type Tone = { frequency: number; to?: number; delay?: number; duration?: number; volume?: number; type?: OscillatorType };

function tone(ctx: AudioContext, { frequency, to, delay = 0, duration = 0.4, volume = 0.18, type = 'sine' }: Tone): void {
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    const start = ctx.currentTime + delay + 0.01;

    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, start);

    if (to) {
        oscillator.frequency.exponentialRampToValueAtTime(to, start + duration * 0.6);
    }

    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    oscillator.connect(gain).connect(ctx.destination);
    oscillator.start(start);
    oscillator.stop(start + duration + 0.05);
}

const sounds: Record<string, (ctx: AudioContext) => void> = {
    /** "Campana": the prototype's Gmail sound. */
    gmail: (ctx) => {
        tone(ctx, { frequency: 880, duration: 1.3, volume: 0.14 });
        tone(ctx, { frequency: 1320, duration: 0.9, volume: 0.06 });
        tone(ctx, { frequency: 1760, duration: 0.5, volume: 0.03 });
    },
    /** "Toc toc": two soft wooden knocks, for Slack messages that wait for an answer. */
    slack: (ctx) => {
        tone(ctx, { frequency: 392, to: 330, duration: 0.16, volume: 0.2, type: 'triangle' });
        tone(ctx, { frequency: 523.25, to: 440, delay: 0.16, duration: 0.22, volume: 0.17, type: 'triangle' });
        tone(ctx, { frequency: 1046.5, delay: 0.17, duration: 0.5, volume: 0.04 });
    },
    /** "Jira": a short rising pair, crisp, for a mention that asks for help. */
    jira: (ctx) => {
        tone(ctx, { frequency: 587.33, duration: 0.14, volume: 0.15, type: 'square' });
        tone(ctx, { frequency: 880, delay: 0.1, duration: 0.35, volume: 0.1, type: 'triangle' });
    },
    /** "Agenda": two soft notes going down, for a task that found no slot. */
    agenda: (ctx) => {
        tone(ctx, { frequency: 880, duration: 0.3, volume: 0.14, type: 'triangle' });
        tone(ctx, { frequency: 659.25, delay: 0.18, duration: 0.5, volume: 0.12, type: 'triangle' });
    },
    /** "Reunión": three short marimba-like notes going up, then the top one again, softer. */
    calendar: (ctx) => {
        [659.25, 830.61, 987.77].forEach((frequency, i) => tone(ctx, { frequency, delay: i * 0.13, duration: 0.32, volume: 0.16, type: 'triangle' }));
        tone(ctx, { frequency: 1318.51, delay: 0.42, duration: 0.6, volume: 0.07, type: 'triangle' });
    },
    /** "Hecho": two bright notes going up, for any confirmed change. */
    done: (ctx) => {
        tone(ctx, { frequency: 784, duration: 0.18, volume: 0.12 });
        tone(ctx, { frequency: 1175, delay: 0.11, duration: 0.45, volume: 0.1 });
    },
    /** "Enviado": a quick rising swoosh, then a soft confirmation note. */
    sent: (ctx) => {
        tone(ctx, { frequency: 520, to: 1560, duration: 0.28, volume: 0.12 });
        tone(ctx, { frequency: 1320, delay: 0.22, duration: 0.55, volume: 0.08 });
    },
};

export function ring(source: string): void {
    const ctx = audio();

    if (ctx) {
        sounds[source]?.(ctx);
    }
}
