/**
 * Plays streamed PCM speech gaplessly, and can stop instantly on barge-in.
 */
export class AudioPlayer {
    /** Called when playback starts after silence, and when it drains. */
    onPlayingChange: (playing: boolean) => void = () => {};

    /** Output context, shared with interface sounds so they mix with the voice. */
    readonly context = new AudioContext();
    private readonly sources = new Set<AudioBufferSourceNode>();
    private nextStartTime = 0;

    get playing(): boolean {
        return this.sources.size > 0;
    }

    enqueue(pcm: Int16Array, sampleRate: number): void {
        if (pcm.length === 0) {
            return;
        }

        void this.context.resume();

        const buffer = this.context.createBuffer(1, pcm.length, sampleRate);
        const channel = buffer.getChannelData(0);

        for (let i = 0; i < pcm.length; i++) {
            channel[i] = pcm[i] / 0x8000;
        }

        const source = this.context.createBufferSource();
        source.buffer = buffer;
        source.connect(this.context.destination);

        const startAt = Math.max(this.context.currentTime, this.nextStartTime);
        source.start(startAt);
        this.nextStartTime = startAt + buffer.duration;

        const wasPlaying = this.playing;
        this.sources.add(source);

        source.onended = () => {
            this.sources.delete(source);

            if (!this.playing) {
                this.onPlayingChange(false);
            }
        };

        if (!wasPlaying) {
            this.onPlayingChange(true);
        }
    }

    interrupt(): void {
        for (const source of this.sources) {
            source.onended = null;
            source.stop();
        }

        const wasPlaying = this.playing;
        this.sources.clear();
        this.nextStartTime = 0;

        if (wasPlaying) {
            this.onPlayingChange(false);
        }
    }

    close(): void {
        this.interrupt();
        void this.context.close();
    }
}
