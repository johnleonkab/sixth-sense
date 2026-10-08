import { GoogleGenAI, Modality, type FunctionDeclaration, type LiveServerMessage, type RealtimeInputConfig, type Session } from '@google/genai';

import { base64ToPcm, pcmToBase64 } from '@/voice/audio/pcm';
import type { ToolResponse, VoiceSessionGrant, VoiceTransport, VoiceTransportEvents } from '@/voice/types';

type GeminiConnection = {
    model: string;
    api_version: string;
    voice: string;
    instructions: string;
    tools: { functionDeclarations: FunctionDeclaration[] }[];
    realtime_input: RealtimeInputConfig;
};

const OUTPUT_SAMPLE_RATE = 24000;

/**
 * Gemini Live API over WebSocket, authenticated with the ephemeral token
 * Laravel issues. The session setup is locked into the token server-side;
 * the same setup is sent here so both sides agree.
 */
export class GeminiTransport implements VoiceTransport {
    readonly inputSampleRate = 16000;

    private session: Session | null = null;
    private closedByClient = false;

    async connect(grant: VoiceSessionGrant, events: VoiceTransportEvents): Promise<void> {
        const connection = grant.connection as GeminiConnection;
        const ai = new GoogleGenAI({
            apiKey: grant.token,
            httpOptions: { apiVersion: connection.api_version },
        });

        this.session = await ai.live.connect({
            model: connection.model,
            config: {
                responseModalities: [Modality.AUDIO],
                speechConfig: {
                    voiceConfig: { prebuiltVoiceConfig: { voiceName: connection.voice } },
                },
                systemInstruction: connection.instructions,
                inputAudioTranscription: {},
                outputAudioTranscription: {},
                tools: connection.tools,
                realtimeInputConfig: connection.realtime_input,
            },
            callbacks: {
                onmessage: (message) => this.handle(message, events),
                onerror: (event) => events.onError(new Error(event.message || 'Gemini Live connection error')),
                onclose: (event) => {
                    if (!this.closedByClient) {
                        events.onClose(event.reason);
                    }
                },
            },
        });
    }

    sendAudio(pcm: Int16Array): void {
        this.session?.sendRealtimeInput({
            audio: { data: pcmToBase64(pcm), mimeType: `audio/pcm;rate=${this.inputSampleRate}` },
        });
    }

    sendToolResponses(responses: ToolResponse[]): void {
        this.session?.sendToolResponse({
            functionResponses: responses.map(({ id, name, output }) => ({ id, name, response: output })),
        });
    }

    sendContext(text: string, respond: boolean): void {
        this.session?.sendClientContent({ turns: [{ role: 'user', parts: [{ text }] }], turnComplete: respond });
    }

    close(): void {
        this.closedByClient = true;
        this.session?.close();
        this.session = null;
    }

    private handle(message: LiveServerMessage, events: VoiceTransportEvents): void {
        const calls = message.toolCall?.functionCalls ?? [];

        if (calls.length > 0) {
            events.onToolCalls(
                calls.map((call) => ({ id: call.id ?? '', name: call.name ?? '', args: call.args ?? {} })),
            );
        }

        if (message.toolCallCancellation?.ids?.length) {
            events.onToolCallsCancelled(message.toolCallCancellation.ids);
        }

        const content = message.serverContent;

        if (!content) {
            return;
        }

        if (content.interrupted) {
            events.onInterrupted();
        }

        if (content.inputTranscription?.text) {
            events.onUserTranscript(content.inputTranscription.text);
        }

        if (content.outputTranscription?.text) {
            events.onAssistantTranscript(content.outputTranscription.text);
        }

        for (const part of content.modelTurn?.parts ?? []) {
            const audio = part.inlineData;

            if (audio?.data && audio.mimeType?.startsWith('audio/pcm')) {
                events.onAssistantAudio(base64ToPcm(audio.data), sampleRateOf(audio.mimeType));
            }
        }

        if (content.turnComplete) {
            events.onTurnComplete();
        }
    }
}

function sampleRateOf(mimeType: string): number {
    const rate = /rate=(\d+)/.exec(mimeType)?.[1];

    return rate ? Number(rate) : OUTPUT_SAMPLE_RATE;
}
