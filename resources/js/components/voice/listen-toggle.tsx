import type { VoiceSession } from '@/voice/use-voice-session';

export default function ListenToggle({ voice }: { voice: VoiceSession }) {
    return (
        <button
            type="button"
            aria-pressed={voice.active}
            onClick={() => (voice.active ? voice.stop() : void voice.start())}
            className="listen-dot inline-flex items-center gap-2 rounded-full border border-line bg-surface py-[5px] pr-3.5 pl-3 text-[13px] text-muted aria-pressed:border-[color-mix(in_srgb,var(--accent)_35%,var(--line))] aria-pressed:text-ink"
        >
            <i className="size-2 rounded-full" />
            {voice.active ? 'Escucha activa' : 'Escucha en pausa'}
        </button>
    );
}
