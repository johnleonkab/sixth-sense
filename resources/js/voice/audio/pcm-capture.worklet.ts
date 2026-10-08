/**
 * AudioWorklet that turns microphone audio into 16-bit mono PCM at the
 * sample rate the voice provider expects, in ~40 ms chunks.
 */

declare const sampleRate: number;
declare function registerProcessor(name: string, processor: unknown): void;
declare class AudioWorkletProcessor {
    readonly port: MessagePort;
    constructor(options?: { processorOptions?: unknown });
}

const CHUNK_MS = 40;

class PcmCaptureProcessor extends AudioWorkletProcessor {
    private readonly ratio: number;
    private readonly chunkSize: number;
    private pending: number[] = [];
    private position = 0;
    private output: number[] = [];

    constructor(options: { processorOptions: { targetSampleRate: number } }) {
        super(options);
        const target = options.processorOptions.targetSampleRate;
        this.ratio = sampleRate / target;
        this.chunkSize = Math.round((target * CHUNK_MS) / 1000);
    }

    process(inputs: Float32Array[][]): boolean {
        const channel = inputs[0]?.[0];

        if (!channel) {
            return true;
        }

        for (let i = 0; i < channel.length; i++) {
            this.pending.push(channel[i]);
        }

        // Box-filter downsampling: average the input samples each output sample covers.
        while (this.position + this.ratio <= this.pending.length) {
            const start = Math.floor(this.position);
            const end = Math.max(start + 1, Math.floor(this.position + this.ratio));
            let sum = 0;

            for (let i = start; i < end; i++) {
                sum += this.pending[i];
            }

            this.output.push(sum / (end - start));
            this.position += this.ratio;
        }

        const consumed = Math.floor(this.position);
        this.pending = this.pending.slice(consumed);
        this.position -= consumed;

        while (this.output.length >= this.chunkSize) {
            const samples = this.output.splice(0, this.chunkSize);
            const pcm = new Int16Array(samples.length);

            for (let i = 0; i < samples.length; i++) {
                const s = Math.max(-1, Math.min(1, samples[i]));
                pcm[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
            }

            this.port.postMessage(pcm, [pcm.buffer]);
        }

        return true;
    }
}

registerProcessor('pcm-capture', PcmCaptureProcessor);
