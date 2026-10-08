/**
 * Short-lived grant issued by Laravel (POST /voice/sessions). The browser uses
 * it to talk to the voice provider directly; Laravel never handles audio.
 */
export type VoiceSessionGrant = {
    provider: string;
    token: string;
    connect_before: string;
    expires_at: string;
    connection: Record<string, unknown>;
};

export type VoiceStatus = 'idle' | 'connecting' | 'listening' | 'thinking' | 'speaking' | 'error';

/** The assistant asked to run one of the tools Laravel declared. */
export type ToolCall = {
    id: string;
    name: string;
    args: Record<string, unknown>;
};

export type ToolResponse = {
    id: string;
    name: string;
    output: Record<string, unknown>;
};

/**
 * Events every provider transport reports, in provider-neutral terms.
 */
export type VoiceTransportEvents = {
    /** A piece of what the person is saying (live transcription). */
    onUserTranscript: (text: string) => void;
    /** A piece of what the assistant is saying. */
    onAssistantTranscript: (text: string) => void;
    /** A chunk of assistant speech as 16-bit little-endian mono PCM. */
    onAssistantAudio: (pcm: Int16Array, sampleRate: number) => void;
    /** The person spoke over the assistant: stop playback now. */
    onInterrupted: () => void;
    /** The assistant finished its turn. */
    onTurnComplete: () => void;
    /** The assistant wants to run tools; it waits for their responses before going on. */
    onToolCalls: (calls: ToolCall[]) => void;
    /** Tool calls the assistant no longer needs (the person interrupted). */
    onToolCallsCancelled: (ids: string[]) => void;
    onClose: (reason?: string) => void;
    onError: (error: Error) => void;
};

/**
 * A live speech-to-speech connection with a provider. Implement one per
 * provider in `transports/` and register it in `transports/index.ts`.
 */
export interface VoiceTransport {
    /** Sample rate the provider expects for microphone audio. */
    readonly inputSampleRate: number;
    connect(grant: VoiceSessionGrant, events: VoiceTransportEvents): Promise<void>;
    /** Send a chunk of microphone audio as 16-bit mono PCM at `inputSampleRate`. */
    sendAudio(pcm: Int16Array): void;
    sendToolResponses(responses: ToolResponse[]): void;
    /**
     * Give the assistant context it did not hear (e.g. an alert that just arrived).
     * With `respond`, it answers right away; otherwise it keeps it in mind.
     */
    sendContext(text: string, respond: boolean): void;
    close(): void;
}
