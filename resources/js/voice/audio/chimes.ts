/**
 * Interface sounds, synthesised with Web Audio (no audio files).
 */

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
