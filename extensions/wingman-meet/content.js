/**
 * Wingman para Meet · spike H0.
 *
 * Runs inside the Meet tab, only in the person's browser: notices when they
 * are in a call (not the waiting room), turns on Meet's own captions just for
 * them, hides them, and keeps what is said and who says it. Nothing is sent
 * anywhere: the popup exports it, to judge whether captions are enough.
 */
(() => {
    const LEAVE_BUTTON = /leave call|salir de la llamada|abandonar la llamada|colgar/i;
    const CAPTIONS_ON_BUTTON = /turn on captions|activar (los )?subt[ií]tulos/i;
    const CAPTIONS_REGION = '[role="region"][aria-label*="aption" i], [role="region"][aria-label*="ubt" i]';
    const MEETING_CODE = /^\/([a-z]{3}-[a-z]{4}-[a-z]{3})(?:$|[/?])/;
    /** A line nobody touched for this long is taken as finished. */
    const SETTLED_AFTER_MS = 2000;
    const RAW_LIMIT = 4000;

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
    };

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
                segment = { id: nextSegmentId++, speaker, text: '', startedAt: now(), updatedAt: now(), final: false };
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
            speakers,
            segments: state.segments,
            raw: state.raw,
            errors: state.errors,
        };
    }

    function tick() {
        const inCall = Boolean(leaveButton());
        const code = location.pathname.match(MEETING_CODE)?.[1] ?? null;

        if (inCall && !state.inCall) {
            Object.assign(state, { inCall: true, code, joinedAt: now(), leftAt: null, segments: [], raw: [], errors: [] });
            live.clear();
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

    setInterval(tick, 1000);
    setInterval(() => state.inCall && void save(), 10_000);
})();
