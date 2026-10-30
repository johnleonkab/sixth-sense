import { Button, Buttons } from '@/components/button';

const taps = [
    { correction: 'not_important', label: 'No hace falta' },
    { correction: 'someone_else', label: 'Le toca a otro' },
    { correction: 'always_notify', label: 'Avísame siempre' },
] as const;

export type SlackCorrection = (typeof taps)[number]['correction'];

/**
 * The three taps. Each one proposes a rule and waits for "sí".
 */
export default function SlackCorrections({ onCorrect, className = 'mt-3' }: { onCorrect: (correction: SlackCorrection) => void; className?: string }) {
    return (
        <Buttons aria-label="Corregir el aviso" className={`gap-1.5 ${className}`}>
            {taps.map((tap) => (
                <Button key={tap.correction} variant="tertiary" size="small" onClick={() => onCorrect(tap.correction)}>
                    {tap.label}
                </Button>
            ))}
        </Buttons>
    );
}
