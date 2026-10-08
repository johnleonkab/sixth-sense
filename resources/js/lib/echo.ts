import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { postJson } from '@/lib/http';

let echo: Echo<'reverb'> | null = null;

/**
 * One WebSocket connection to Reverb for the whole app, opened on first use.
 * Private channels are authorised with the session, like any other request.
 */
export function echoConnection(): Echo<'reverb'> {
    echo ??= new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            endpoint: '/broadcasting/auth',
            transport: 'ajax',
            customHandler: ({ socketId, channelName }, callback) => {
                postJson<{ auth: string }>('/broadcasting/auth', { socket_id: socketId, channel_name: channelName })
                    .then((data) => callback(null, data))
                    .catch((error: Error) => callback(error, null));
            },
        },
    });

    return echo;
}
