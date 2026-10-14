/**
 * Wingman para Meet · the link with sixth-sense.
 *
 * The Meet tab tells this worker what happens in the call; the worker sends
 * it to sixth-sense with the person's token. What could not be sent (no
 * connection, sixth-sense down) is kept and sent with the next batch.
 */
const PENDING_KEY = 'wingman:pending';
const PENDING_LIMIT = 2000;

async function settings() {
    const { server, token } = await chrome.storage.local.get(['server', 'token']);

    return server && token ? { server: server.replace(/\/+$/, ''), token } : null;
}

async function call(path, body) {
    const config = await settings();

    if (!config) {
        throw new Error('Wingman no está conectado: abre la extensión y conéctala.');
    }

    const response = await fetch(`${config.server}/api/wingman/extension/${path}`, {
        method: body === undefined ? 'GET' : 'POST',
        headers: { Authorization: `Bearer ${config.token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(data.message ?? `sixth-sense respondió ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return data;
}

/** Segments that could not be sent, by meeting: the latest version of each wins. */
async function keep(meetingId, segments) {
    const { [PENDING_KEY]: pending = {} } = await chrome.storage.local.get(PENDING_KEY);
    const kept = { ...(pending[meetingId] ?? {}) };
    segments.forEach((segment) => (kept[segment.client_id] = segment));
    const ids = Object.keys(kept);
    ids.slice(0, Math.max(0, ids.length - PENDING_LIMIT)).forEach((id) => delete kept[id]);
    await chrome.storage.local.set({ [PENDING_KEY]: { ...pending, [meetingId]: kept } });
}

async function takePending(meetingId) {
    const { [PENDING_KEY]: pending = {} } = await chrome.storage.local.get(PENDING_KEY);
    const kept = Object.values(pending[meetingId] ?? {});
    delete pending[meetingId];
    await chrome.storage.local.set({ [PENDING_KEY]: pending });

    return kept;
}

async function sendSegments(meetingId, segments) {
    const batch = [...(await takePending(meetingId)), ...segments];
    const latest = new Map(batch.map((segment) => [segment.client_id, segment]));
    const all = [...latest.values()];

    for (let start = 0; start < all.length; start += 200) {
        const chunk = all.slice(start, start + 200);

        try {
            await call(`calls/${meetingId}/segments`, { segments: chunk });
        } catch (error) {
            await keep(meetingId, all.slice(start));
            throw error;
        }
    }

    return all.length;
}

/** How the toolbar icon says where Wingman stands in a tab. */
const BADGES = {
    listening: { text: '•', color: '#2e56e6' },
    paused: { text: 'II', color: '#8b8f8d' },
    error: { text: '!', color: '#c42b3d' },
    off: { text: '', color: '#2e56e6' },
};

function badge(tabId, state) {
    const { text, color } = BADGES[state] ?? BADGES.off;

    if (tabId === undefined) {
        return;
    }

    void chrome.action.setBadgeText({ tabId, text });
    void chrome.action.setBadgeBackgroundColor({ tabId, color });
    void chrome.action.setTitle({ tabId, title: { listening: 'Wingman · escuchando', paused: 'Wingman · en pausa', error: 'Wingman · sin conexión con sixth-sense', off: 'Wingman' }[state] ?? 'Wingman' });
}

chrome.runtime.onMessage.addListener((message, sender, reply) => {
    if (message.type === 'badge') {
        badge(sender.tab?.id, message.state);
        reply({ ok: true });

        return false;
    }

    const run = async () => {
        switch (message.type) {
            case 'call-start':
                return call('calls', { code: message.code, joined_at: message.joinedAt });
            case 'segments':
                return { sent: await sendSegments(message.meetingId, message.segments) };
            case 'call-end':
                await sendSegments(message.meetingId, message.segments ?? []).catch(() => null);
                return call(`calls/${message.meetingId}/end`, { left_at: message.leftAt });
            case 'ping':
                return call('ping');
            default:
                return null;
        }
    };

    run()
        .then((data) => reply({ ok: true, data }))
        .catch((error) => reply({ ok: false, error: error.message, status: error.status ?? null }));

    // The answer comes later.
    return true;
});
