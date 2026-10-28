const taps = [
    { correction: 'not_important', label: 'No hace falta' },
    { correction: 'someone_else', label: 'Le toca a otro' },
    { correction: 'always_notify', label: 'Avísame siempre' },
] as const;

export type SlackCorrection = (typeof taps)[number]['correction'];

/**
 * The three taps. Each one proposes a rule and waits for "sí".
 */
export default function SlackCorrections({ onCorrect }: { onCorrect: (correction: SlackCorrection) => void }) {
    return (
        <div className="mt-3 flex flex-wrap gap-1.5">
            {taps.map((tap) => (
                <button
                    key={tap.correction}
                    type="button"
                    onClick={() => onCorrect(tap.correction)}
                    className="rounded-full border border-line px-2.5 py-1 text-[12px] text-ink hover:bg-soft"
                >
                    {tap.label}
                </button>
            ))}
        </div>
    );
}
