import type { VoiceSession } from '@/voice/use-voice-session';

export default function ListenToggle({ voice }: { voice: VoiceSession }) {
    return (
        <button
            type="button"
            aria-pressed={voice.active}
            onClick={() => (voice.active ? voice.stop() : void voice.start())}
            aria-label={voice.active ? 'Pausar la escucha' : 'Activar la escucha'}
            className="listen-dot inline-flex size-11 items-center justify-center gap-2 rounded-full border border-line bg-surface text-[13px] text-muted aria-pressed:border-[color-mix(in_srgb,var(--accent)_35%,var(--line))] aria-pressed:text-ink md:size-auto md:py-[5px] md:pr-3.5 md:pl-3"
        >
            <i className="size-2 rounded-full" />
            <span className="max-md:sr-only">{voice.active ? 'Escucha activa' : 'Escucha en pausa'}</span>
        </button>
    );
}
