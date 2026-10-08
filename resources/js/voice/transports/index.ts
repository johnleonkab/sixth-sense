import type { VoiceTransport } from '@/voice/types';

/**
 * Transports are loaded on demand, so a provider's SDK is only downloaded
 * when a conversation starts.
 */
const transports: Record<string, () => Promise<VoiceTransport>> = {
    gemini: async () => new (await import('./gemini')).GeminiTransport(),
};

export async function createTransport(provider: string): Promise<VoiceTransport> {
    const factory = transports[provider];

    if (!factory) {
        throw new Error(`Unsupported voice provider [${provider}].`);
    }

    return factory();
}
