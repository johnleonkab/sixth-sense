import { useEffect, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';

import VoiceOrb from '@/components/voice/voice-orb';
import { press, type TapKind } from '@/voice/haptics';
import { useTypewriter } from '@/voice/use-typewriter';
import type { VoiceSession } from '@/voice/use-voice-session';

function MicrophoneIcon({ off = false }: { off?: boolean }) {
    return (
        <svg viewBox="0 0 24 24" className="size-[1.15em]" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden>
            <rect x="9" y="3" width="6" height="11" rx="3" />
            <path d="M5 11a7 7 0 0 0 14 0M12 18v3" strokeLinecap="round" />
            {off && <path d="M5 5l14 14" strokeLinecap="round" />}
        </svg>
    );
}

function StopIcon() {
    return (
        <svg viewBox="0 0 24 24" className="size-[1.05em]" fill="currentColor" aria-hidden>
            <rect x="6.5" y="6.5" width="11" height="11" rx="2" />
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
            return voice.muted ? 'Micrófono silenciado.' : 'Te escucho.';
        case 'speaking':
            return 'Te escucho.';
        default:
            return '';
    }
}

/**
 * A voice key: click sound, vibration, and the press-in of `.press`.
 */
function PressButton({
    tap,
    className = '',
    children,
    onClick,
    ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { tap: TapKind; children: ReactNode }) {
    return (
        <button
            type="button"
            {...rest}
            className={className}
            onPointerDown={(event) => {
                rest.onPointerDown?.(event);

                if (event.button === 0) {
                    press(tap);
                }
            }}
            onClick={(event) => {
                if (event.detail === 0) {
                    press(tap);
                }

                onClick?.(event);
            }}
        >
            {children}
        </button>
    );
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
            <p className="mx-auto min-h-[1.2em] max-w-full px-2 text-[14px] text-muted md:min-h-[23px] md:text-[15px]">
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
            className="mx-auto block max-w-full cursor-text rounded-md px-2 text-[13px] text-faint hover:bg-soft md:min-h-[23px] md:text-[15px]"
        >
            {voice.heard || 'o escribe aquí…'}
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

function markStandalone(): void {
    const standalone =
        window.matchMedia('(display-mode: standalone)').matches ||
        window.matchMedia('(display-mode: fullscreen)').matches ||
        window.matchMedia('(display-mode: minimal-ui)').matches ||
        ('standalone' in navigator && Boolean((navigator as Navigator & { standalone?: boolean }).standalone));

    document.documentElement.toggleAttribute('data-standalone', standalone);
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
    const spoken = voice.status === 'error' || showsReply || voice.active;
    const replyText = voice.status === 'error' ? voice.error : showsReply ? typedReply : placeholderReply(voice);

    useEffect(() => {
        markStandalone();
        const media = window.matchMedia('(display-mode: standalone)');
        media.addEventListener('change', markStandalone);

        return () => media.removeEventListener('change', markStandalone);
    }, []);

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
            className="voice-dock chrome pointer-events-none absolute inset-x-0 bottom-0 z-20 pr-[max(1rem,env(safe-area-inset-right))] pl-[max(1rem,env(safe-area-inset-left))] text-center"
        >
            {/* Floats over the canvas: frosted glass that fades in from the top, so what is behind still shows. */}
            <div aria-hidden className="absolute inset-0 bg-bg/70 [mask-image:linear-gradient(to_bottom,transparent,black_1.25rem)] backdrop-blur-xl md:[mask-image:linear-gradient(to_bottom,transparent,black_2.5rem)]" />

            <div className="pointer-events-auto relative mx-auto w-full max-w-[760px]">
                <div className="mb-1 md:mb-1.5">
                    <VoiceOrb status={voice.status} />
                </div>

                {agent && (
                    <p className="mb-1.5 inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-2.5 py-0.5 text-[12px] text-ink md:mb-2">
                        <i aria-hidden className="size-1.5 rounded-full bg-accent" />
                        {agent}
                    </p>
                )}

                <SaidLine voice={voice} />

                <p aria-live="polite" className="sr-only">
                    {voice.status === 'error' ? voice.error : showsReply ? voice.reply : placeholderReply(voice)}
                </p>

                {spoken && replyText !== '' && (
                    <p
                        aria-hidden
                        data-reply
                        className={
                            'mx-auto mt-0.5 line-clamp-3 max-w-[680px] text-[16px] leading-[1.35] tracking-[-0.015em] text-pretty md:mt-1 md:min-h-[26px] md:text-[19px] md:leading-[1.4] ' +
                            (voice.status === 'error' ? 'text-crit' : showsReply ? 'text-ink' : 'text-faint')
                        }
                    >
                        {replyText}
                    </p>
                )}

                <div className="voice-keys mt-2 md:mt-2.5" data-live={voice.active ? 'on' : undefined}>
                    <PressButton
                        tap={voice.active ? 'stop' : 'talk'}
                        onClick={() => (voice.active ? voice.stop() : void voice.start('voice'))}
                        className={`voice-key-main ${voice.active ? 'is-stop' : ''}`}
                    >
                        <span key={voice.active ? 'stop' : 'talk'} className="voice-key-label">
                            {voice.active ? <StopIcon /> : <MicrophoneIcon />}
                            {voice.active ? 'Parar' : 'Hablar'}
                        </span>
                    </PressButton>
                    <div className="voice-mute-slot">
                        <PressButton
                            tap={voice.muted ? 'unmute' : 'mute'}
                            aria-pressed={voice.muted}
                            aria-hidden={voice.active ? undefined : true}
                            tabIndex={voice.active ? 0 : -1}
                            aria-label={voice.muted ? 'Activar el micrófono' : 'Silenciar el micrófono'}
                            title={voice.muted ? 'Activar el micrófono' : 'Silenciar'}
                            onClick={() => voice.active && voice.setMuted(!voice.muted)}
                            className="voice-key-mute"
                        >
                            <MicrophoneIcon off={voice.muted} />
                        </PressButton>
                    </div>
                </div>
            </div>
        </section>
    );
}
