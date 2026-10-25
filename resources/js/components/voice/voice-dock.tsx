import { useEffect, useRef, useState } from 'react';

import VoiceOrb from '@/components/voice/voice-orb';
import { useTypewriter } from '@/voice/use-typewriter';
import type { VoiceSession } from '@/voice/use-voice-session';

function MicrophoneIcon() {
    return (
        <svg viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden>
            <rect x="9" y="3" width="6" height="11" rx="3" />
            <path d="M5 11a7 7 0 0 0 14 0M12 18v3" strokeLinecap="round" />
        </svg>
    );
}

function placeholderReply(voice: VoiceSession): string {
    switch (voice.status) {
        case 'connecting':
            return voice.reconnecting ? 'Se ha cortado la conexión. Reconectando…' : 'Conectando…';
        case 'thinking':
            return voice.activity ?? 'Pensando…';
        case 'listening':
        case 'speaking':
            return 'Te escucho.';
        default:
            return 'Pulsa «Hablar» y pregúntame lo que quieras.';
    }
}

/**
 * The line where what the person says appears. When they are not talking, it
 * can be clicked to type instead; the answer then comes back as text only.
 */
function SaidLine({ voice }: { voice: VoiceSession }) {
    const [typing, setTyping] = useState(false);
    const [draft, setDraft] = useState('');

    // Talking takes over from typing.
    useEffect(() => {
        if (voice.active) {
            setTyping(false);
        }
    }, [voice.active]);

    if (voice.active) {
        return (
            <p className="mx-auto min-h-[23px] max-w-full px-2 text-[15px] text-muted">
                {voice.heard}
                <span aria-hidden className="ml-0.5 inline-block h-[15px] w-px translate-y-[2px] animate-pulse bg-accent" />
            </p>
        );
    }

    if (typing) {
        const close = () => {
            setDraft('');
            setTyping(false);
        };

        return (
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    void voice.ask(draft);
                    close();
                }}
            >
                <input
                    autoFocus
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => event.key === 'Escape' && close()}
                    onBlur={() => draft.trim() === '' && close()}
                    aria-label="Escribe lo que dirías en voz alta"
                    placeholder="Escribe lo que dirías en voz alta"
                    autoComplete="off"
                    className="mx-auto block min-h-11 w-full max-w-[560px] border-0 bg-transparent p-0 text-center text-base text-ink caret-accent outline-none placeholder:text-faint md:min-h-[23px] md:text-[15px]"
                />
            </form>
        );
    }

    return (
        <button
            type="button"
            onClick={() => setTyping(true)}
            title="Escribir en lugar de hablar"
            className="mx-auto block min-h-[23px] max-w-full cursor-text rounded-md px-2 text-[15px] text-muted hover:bg-soft"
        >
            {voice.heard || <span className="text-faint">o escribe aquí…</span>}
        </button>
    );
}

/**
 * How far the on-screen keyboard covers the bottom of the layout, on a phone.
 * The dock rises by that much so typing stays visible.
 */
function useKeyboardLift(): number {
    const [lift, setLift] = useState(0);

    useEffect(() => {
        const viewport = window.visualViewport;

        if (!viewport) {
            return;
        }

        const update = () => {
            if (window.matchMedia('(min-width: 768px)').matches) {
                setLift(0);

                return;
            }

            const covered = window.innerHeight - viewport.height - viewport.offsetTop;
            setLift(covered > 80 ? covered : 0);
        };

        viewport.addEventListener('resize', update);
        viewport.addEventListener('scroll', update);
        update();

        return () => {
            viewport.removeEventListener('resize', update);
            viewport.removeEventListener('scroll', update);
        };
    }, []);

    return lift;
}

/**
 * Bottom voice zone: what the person says, what the assistant answers, and
 * the control to start or end the conversation.
 */
export default function VoiceDock({ voice, agent }: { voice: VoiceSession; agent?: string | null }) {
    const showsReply = voice.status !== 'error' && voice.status !== 'thinking' && voice.reply !== '';
    const typedReply = useTypewriter(showsReply ? voice.reply : '');
    const lift = useKeyboardLift();
    const dock = useRef<HTMLElement>(null);

    useEffect(() => {
        const element = dock.current;

        if (!element) {
            return;
        }

        const apply = () => document.documentElement.style.setProperty('--voice-dock-height', `${element.offsetHeight}px`);
        apply();
        const observer = new ResizeObserver(apply);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        document.documentElement.style.setProperty('--keyboard-lift', `${lift}px`);
    }, [lift]);

    return (
        <section
            ref={dock}
            style={lift > 0 ? { transform: `translateY(-${lift}px)` } : undefined}
            className="chrome relative mx-auto w-full max-w-[760px] pt-3.5 pb-[max(1.25rem,env(safe-area-inset-bottom))] text-center"
        >
            <div
                aria-hidden
                className="pointer-events-none absolute -inset-x-4 -top-10 h-10 bg-linear-to-b from-transparent to-bg"
            />

            <div className="mb-2.5">
                <VoiceOrb status={voice.status} />
            </div>

            {agent && (
                <p className="mb-2 inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 py-0.5 text-[12px] text-ink">
                    <i aria-hidden className="size-1.5 rounded-full bg-accent" />
                    {agent}
                </p>
            )}

            <SaidLine voice={voice} />

            <p aria-live="polite" className="sr-only">
                {voice.status === 'error' ? voice.error : showsReply ? voice.reply : placeholderReply(voice)}
            </p>

            <p
                aria-hidden
                data-reply
                className={
                    'mx-auto mt-1.5 min-h-[30px] max-w-[680px] text-[18px] leading-[1.35] tracking-[-0.015em] text-pretty md:text-[21px] md:leading-[1.4] ' +
                    (voice.status === 'error' ? 'text-crit' : showsReply ? 'text-ink' : 'text-faint')
                }
            >
                {voice.status === 'error' ? voice.error : showsReply ? typedReply : placeholderReply(voice)}
            </p>

            <div className="mt-4 flex justify-center">
                {voice.active ? (
                    <button
                        type="button"
                        onClick={voice.stop}
                        className="min-h-14 w-full rounded-2xl border border-line bg-surface text-[16px] font-medium text-ink shadow-card hover:bg-soft md:min-h-0 md:w-auto md:rounded-full md:px-4 md:py-1.5 md:text-[13px] md:font-normal md:shadow-none"
                    >
                        Terminar
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={() => void voice.start('voice')}
                        className="inline-flex min-h-14 w-full items-center justify-center gap-2.5 rounded-2xl bg-accent text-[17px] font-medium text-accent-ink shadow-card transition-opacity hover:opacity-90 md:min-h-0 md:w-auto md:gap-2 md:rounded-full md:px-5 md:py-2 md:text-[14px]"
                    >
                        <MicrophoneIcon />
                        Hablar
                    </button>
                )}
            </div>
        </section>
    );
}
