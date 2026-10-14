/**
 * Wingman para Meet.
 *
 * Runs inside the Meet tab, only in the person's browser: notices when they
 * are in a call (not the waiting room), turns on Meet's own captions just for
 * them, hides them, and keeps what is said and who says it. The extension's
 * worker sends it to sixth-sense; the popup can also export it.
 */
(() => {
    const LEAVE_BUTTON = /leave call|salir de la llamada|abandonar la llamada|colgar/i;
    const CAPTIONS_ON_BUTTON = /turn on captions|activar (los )?subt[ií]tulos/i;
    const CAPTIONS_REGION = '[role="region"][aria-label*="aption" i], [role="region"][aria-label*="ubt" i]';
    const MEETING_CODE = /^\/([a-z]{3}-[a-z]{4}-[a-z]{3})(?:$|[/?])/;
    /** A line nobody touched for this long is taken as finished. */
    const SETTLED_AFTER_MS = 2000;
    const RAW_LIMIT = 4000;
    /** What changed is sent this often. */
    const SEND_EVERY_MS = 2000;
    /** Telling sixth-sense about the call is tried again this often until it answers. */
    const RETRY_START_MS = 10_000;

    const state = {
        inCall: false,
        code: null,
        joinedAt: null,
        leftAt: null,
        captionsFound: false,
        captionsTurnedOnByUs: false,
        hidden: true,
        segments: [],
        raw: [],
        errors: [],
        // The link with sixth-sense.
        meetingId: null,
        meetingTitle: null,
        sent: 0,
        lastError: null,
        paused: false,
    };

    /** The version of each segment sixth-sense already has. */
    const sentVersions = new Map();
    let startTimer = null;

    /** Each caption block on screen, and the segment it is writing. */
    const live = new Map();
    let nextSegmentId = 1;
    let observer = null;

    const now = () => new Date().toISOString();

    function leaveButton() {
        return [...document.querySelectorAll('button[aria-label]')].find((button) => LEAVE_BUTTON.test(button.getAttribute('aria-label')));
    }

    function captionsRegion() {
        return document.querySelector(CAPTIONS_REGION);
    }

    function turnOnCaptions() {
        const button = [...document.querySelectorAll('button[aria-label]')].find((candidate) => CAPTIONS_ON_BUTTON.test(candidate.getAttribute('aria-label')));

        if (button) {
            button.click();
            state.captionsTurnedOnByUs = true;
        }
    }

    function applyHiding() {
        let style = document.getElementById('wingman-hide-captions');

        if (state.hidden && !style) {
            style = document.createElement('style');
            style.id = 'wingman-hide-captions';
            // Transparent, not removed: Meet keeps writing them, and only this person's screen changes.
            style.textContent = `${CAPTIONS_REGION} { opacity: 0 !important; pointer-events: none !important; }`;
            document.documentElement.append(style);
        } else if (!state.hidden && style) {
            style.remove();
        }
    }

    /**
     * The caption blocks in the region: an avatar, the speaker's name on the
     * first line, what they say below it.
     */
    function blocks(region) {
        const found = new Set();

        for (const avatar of region.querySelectorAll('img')) {
            let block = avatar.parentElement;

            while (block && block !== region && block.innerText.split('\n').filter(Boolean).length < 2) {
                block = block.parentElement;
            }

            if (block && block !== region) {
                found.add(block);
            }
        }

        return [...found].map((element) => {
            const lines = element.innerText.split('\n').map((line) => line.trim()).filter(Boolean);

            return { element, speaker: lines[0] ?? '', text: lines.slice(1).join(' ') };
        });
    }

    /**
     * Meet shows only the last lines of a long turn: the new text may start
     * with the end of what was already kept. Join them on that overlap.
     */
    function merge(kept, shown) {
        if (shown.startsWith(kept) || kept === '') {
            return shown;
        }

        const keptWords = kept.split(' ');
        const shownWords = shown.split(' ');

        for (let size = Math.min(keptWords.length, shownWords.length); size >= 3; size--) {
            if (keptWords.slice(-size).join(' ') === shownWords.slice(0, size).join(' ')) {
                return [...keptWords, ...shownWords.slice(size)].join(' ');
            }
        }

        // No overlap: Meet corrected the last words. What is shown now wins.
        return shown.length >= kept.length * 0.6 ? shown : `${kept} ${shown}`;
    }

    function read() {
        const region = captionsRegion();
        state.captionsFound = Boolean(region);

        if (!region) {
            return;
        }

        const seen = new Set();

        for (const { element, speaker, text } of blocks(region)) {
            if (!text) {
                continue;
            }

            seen.add(element);
            let segment = live.get(element);

            if (!segment || segment.speaker !== speaker) {
                // Unique across reloads of the tab: the server keeps segments by this id.
                segment = { id: `${state.joinedAt}#${nextSegmentId++}`, speaker, text: '', startedAt: now(), updatedAt: now(), final: false };
                live.set(element, segment);
                state.segments.push(segment);
            }

            const merged = merge(segment.text, text);

            if (merged !== segment.text) {
                segment.text = merged;
                segment.updatedAt = now();
                // A pause, then more of the same turn: it is open again.
                segment.final = false;
                state.raw.push({ at: segment.updatedAt, speaker, shown: text });
                state.raw.splice(0, Math.max(0, state.raw.length - RAW_LIMIT));
            }
        }

        // Blocks Meet removed: their segments are finished.
        for (const [element, segment] of live) {
            if (!seen.has(element) || !element.isConnected) {
                segment.final = true;
                live.delete(element);
            }
        }
    }

    function settle() {
        const limit = Date.now() - SETTLED_AFTER_MS;

        for (const segment of state.segments) {
            if (!segment.final && Date.parse(segment.updatedAt) < limit) {
                segment.final = true;
            }
        }
    }

    function watchCaptions() {
        observer?.disconnect();
        let scheduled = false;

        observer = new MutationObserver(() => {
            if (scheduled) {
                return;
            }

            scheduled = true;
            requestAnimationFrame(() => {
                scheduled = false;

                try {
                    read();
                } catch (error) {
                    state.errors.push({ at: now(), message: String(error) });
                }
            });
        });

        observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }

    async function save() {
        if (!state.code) {
            return;
        }

        await chrome.storage.local.set({ [`wingman:${state.code}:${state.joinedAt}`]: snapshot() });
    }

    function snapshot() {
        const speakers = [...new Set(state.segments.map((segment) => segment.speaker))];

        return {
            code: state.code,
            inCall: state.inCall,
            joinedAt: state.joinedAt,
            leftAt: state.leftAt,
            captionsFound: state.captionsFound,
            captionsTurnedOnByUs: state.captionsTurnedOnByUs,
            hidden: state.hidden,
            meetingId: state.meetingId,
            meetingTitle: state.meetingTitle,
            sent: state.sent,
            lastError: state.lastError,
            paused: state.paused,
            speakers,
            segments: state.segments,
            raw: state.raw,
            errors: state.errors,
        };
    }

    /** Ask the extension's worker; a reloaded extension leaves this tab without one. */
    async function tell(message) {
        try {
            return (await chrome.runtime.sendMessage(message)) ?? { ok: false, error: 'Sin respuesta de la extensión' };
        } catch (error) {
            return { ok: false, error: String(error.message ?? error) };
        }
    }

    /** The toolbar icon of this tab says whether Wingman listens. */
    let shownBadge = null;

    function showBadge() {
        const now = !state.inCall || !state.meetingId ? (state.inCall && state.lastError ? 'error' : 'off') : state.lastError ? 'error' : state.paused ? 'paused' : 'listening';

        if (now !== shownBadge) {
            shownBadge = now;
            void tell({ type: 'badge', state: now });
        }
    }

    async function startCall() {
        clearTimeout(startTimer);

        if (!state.inCall || state.meetingId) {
            return;
        }

        const answer = await tell({ type: 'call-start', code: state.code, joinedAt: state.joinedAt });

        if (answer.ok) {
            state.meetingId = answer.data.meeting_id;
            state.meetingTitle = answer.data.title;
            state.lastError = null;
            await flush();
        } else {
            state.lastError = answer.error;
            startTimer = setTimeout(startCall, RETRY_START_MS);
        }
    }

    /** Segments sixth-sense does not have in their current version. */
    function unsent() {
        return state.segments
            .filter((segment) => segment.text && sentVersions.get(segment.id) !== `${segment.final}|${segment.text}`)
            .map((segment) => ({ client_id: segment.id, speaker: segment.speaker, text: segment.text, final: segment.final, spoken_at: segment.startedAt }));
    }

    function markSent(segments) {
        segments.forEach((segment) => sentVersions.set(segment.client_id, `${segment.final}|${segment.text}`));
        state.sent = sentVersions.size;
    }

    async function flush() {
        const segments = unsent();

        if (!state.meetingId || segments.length === 0) {
            return;
        }

        const answer = await tell({ type: 'segments', meetingId: state.meetingId, segments });

        // When it fails, the worker keeps them and sends them with the next batch.
        markSent(segments);
        state.lastError = answer.ok ? null : answer.error;
        // Paused from sixth-sense: what is said is not kept there meanwhile.
        state.paused = answer.ok ? Boolean(answer.data?.paused) : state.paused;
    }

    async function endCall() {
        clearTimeout(startTimer);

        if (!state.meetingId) {
            return;
        }

        const segments = unsent();
        const answer = await tell({ type: 'call-end', meetingId: state.meetingId, segments, leftAt: state.leftAt ?? now() });
        markSent(segments);
        state.lastError = answer.ok ? null : answer.error;
    }

    function tick() {
        const inCall = Boolean(leaveButton());
        const code = location.pathname.match(MEETING_CODE)?.[1] ?? null;

        if (inCall && !state.inCall) {
            Object.assign(state, { inCall: true, code, joinedAt: now(), leftAt: null, segments: [], raw: [], errors: [], meetingId: null, meetingTitle: null, sent: 0, lastError: null, paused: false });
            live.clear();
            sentVersions.clear();
            void startCall();
            applyHiding();
            watchCaptions();
            setTimeout(() => !captionsRegion() && turnOnCaptions(), 1500);
        }

        if (!inCall && state.inCall) {
            state.inCall = false;
            state.leftAt = now();
            observer?.disconnect();
            state.segments.forEach((segment) => (segment.final = true));
            void save();
            void endCall();
        }

        if (state.inCall) {
            // Captions can be turned off by hand, or come back after a reconnection.
            if (!captionsRegion() && !state.captionsTurnedOnByUs) {
                turnOnCaptions();
            }

            settle();
        }
    }

    chrome.runtime.onMessage.addListener((message, _sender, reply) => {
        switch (message.type) {
            case 'status':
                reply(snapshot());
                break;
            case 'set-hidden':
                state.hidden = Boolean(message.hidden);
                applyHiding();
                reply(snapshot());
                break;
            case 'captions-html':
                reply({ html: captionsRegion()?.outerHTML ?? null });
                break;
            default:
                reply(null);
        }
    });

    setInterval(() => {
        tick();
        showBadge();
    }, 1000);
    setInterval(() => state.inCall && void flush(), SEND_EVERY_MS);

    // The tab closes while in the call: it is over for sixth-sense too.
    addEventListener('pagehide', () => {
        if (state.inCall) {
            state.leftAt = now();
            state.segments.forEach((segment) => (segment.final = true));
            void endCall();
        }
    });
    setInterval(() => state.inCall && void save(), 10_000);
})();
