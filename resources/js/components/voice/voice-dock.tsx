import { useEffect, useState } from 'react';

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
            return 'Un momento…';
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
                    className="mx-auto block min-h-[23px] w-full max-w-[560px] border-0 bg-transparent p-0 text-center text-[15px] text-ink caret-accent outline-none placeholder:text-faint"
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
 * Bottom voice zone: what the person says, what the assistant answers, and
 * the control to start or end the conversation.
 */
export default function VoiceDock({ voice }: { voice: VoiceSession }) {
    const showsReply = voice.status !== 'error' && voice.reply !== '';
    const typedReply = useTypewriter(showsReply ? voice.reply : '');

    return (
        <section className="relative mx-auto w-full max-w-[760px] pt-3.5 pb-[22px] text-center">
            <div
                aria-hidden
                className="pointer-events-none absolute -inset-x-4 -top-10 h-10 bg-linear-to-b from-transparent to-bg"
            />

            <div className="mb-2.5">
                <VoiceOrb status={voice.status} />
            </div>

            <SaidLine voice={voice} />

            <p aria-live="polite" className="sr-only">
                {voice.status === 'error' ? voice.error : showsReply ? voice.reply : placeholderReply(voice)}
            </p>

            <p
                aria-hidden
                data-reply
                className={
                    'mx-auto mt-1.5 min-h-[30px] max-w-[680px] text-[21px] leading-[1.4] tracking-[-0.015em] text-pretty ' +
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
                        className="rounded-full border border-line bg-surface px-4 py-1.5 text-[13px] text-ink hover:bg-soft"
                    >
                        Terminar
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={() => void voice.start('voice')}
                        className="inline-flex items-center gap-2 rounded-full bg-accent px-5 py-2 text-[14px] font-medium text-accent-ink shadow-card transition-opacity hover:opacity-90"
                    >
                        <MicrophoneIcon />
                        Hablar
                    </button>
                )}
            </div>
        </section>
    );
}
