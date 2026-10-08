import type { VoiceStatus } from '@/voice/types';

export default function VoiceOrb({ status }: { status: VoiceStatus }) {
    return (
        <div className="orb" data-status={status} aria-hidden>
            {Array.from({ length: 7 }, (_, i) => (
                <span key={i} />
            ))}
        </div>
    );
}
