import workletUrl from './pcm-capture.worklet.ts?worker&url';

/**
 * Captures the microphone as 16-bit mono PCM chunks at `sampleRate`.
 */
export class Microphone {
    onChunk: (pcm: Int16Array) => void = () => {};

    private constructor(
        private readonly context: AudioContext,
        private readonly stream: MediaStream,
        private readonly node: AudioWorkletNode,
    ) {
        node.port.onmessage = (event: MessageEvent<Int16Array>) => this.onChunk(event.data);
    }

    static async open(sampleRate: number): Promise<Microphone> {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });

        const context = new AudioContext();

        try {
            await context.audioWorklet.addModule(workletUrl);

            const node = new AudioWorkletNode(context, 'pcm-capture', {
                processorOptions: { targetSampleRate: sampleRate },
            });

            context.createMediaStreamSource(stream).connect(node);

            return new Microphone(context, stream, node);
        } catch (error) {
            stream.getTracks().forEach((track) => track.stop());
            await context.close();

            throw error;
        }
    }

    close(): void {
        this.node.port.onmessage = null;
        this.node.disconnect();
        this.stream.getTracks().forEach((track) => track.stop());
        void this.context.close();
    }
}
