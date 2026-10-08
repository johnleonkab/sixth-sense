import { browserTimezone, HttpError, postJson } from '@/lib/http';
import type { VoiceSessionGrant } from '@/voice/types';

export class VoiceGrantError extends Error {
    /**
     * @param permanent Trying again will not help (signed out, no permission), unlike being offline.
     */
    constructor(
        message: string,
        public readonly permanent = false,
    ) {
        super(message);
    }
}

export async function requestGrant(): Promise<VoiceSessionGrant> {
    try {
        return await postJson<VoiceSessionGrant>('/voice/sessions', { timezone: browserTimezone() });
    } catch (error) {
        throw new VoiceGrantError(
            error instanceof HttpError && error.status === 503
                ? error.message
                : 'No puedo conectar con el servicio de voz ahora mismo.',
            error instanceof HttpError && [401, 403, 419].includes(error.status),
        );
    }
}
