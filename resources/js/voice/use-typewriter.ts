import { useEffect, useRef, useState } from 'react';

/** Roughly the pace of the spoken reply, so text and voice move together. */
const BASE_CHARS_PER_SECOND = 24;
/** Never fall more than this many characters behind the incoming text. */
const MAX_LAG = 90;

const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Reveals `text` character by character. When the text grows (streaming), it
 * keeps typing from where it was; when it is replaced, it starts again.
 */
export function useTypewriter(text: string): string {
    const [shown, setShown] = useState(0);
    const shownRef = useRef(0);
    const previousText = useRef('');

    if (!text.startsWith(previousText.current.slice(0, shownRef.current))) {
        shownRef.current = 0;
    }

    previousText.current = text;

    useEffect(() => {
        if (prefersReducedMotion()) {
            shownRef.current = text.length;
            setShown(text.length);

            return;
        }

        let frame = 0;
        let last = performance.now();
        let progress = shownRef.current;

        const tick = (now: number) => {
            const elapsed = (now - last) / 1000;
            last = now;

            const lag = text.length - progress;
            const speed = BASE_CHARS_PER_SECOND * (1 + Math.max(0, lag - MAX_LAG) / MAX_LAG);
            progress = Math.min(text.length, progress + speed * elapsed);

            const next = Math.floor(progress);

            if (next !== shownRef.current) {
                shownRef.current = next;
                setShown(next);
            }

            if (progress < text.length) {
                frame = requestAnimationFrame(tick);
            }
        };

        setShown(shownRef.current);
        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [text]);

    return text.slice(0, Math.min(shown, shownRef.current, text.length));
}
