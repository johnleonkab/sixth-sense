import { useState } from 'react';

import { Button } from '@/components/button';

/** A date and time until which the card stays off the home. */
export default function SnoozePicker({ onSnooze }: { onSnooze: (until: string) => void }) {
    const [until, setUntil] = useState('');

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(event) => {
                event.preventDefault();

                if (until !== '') {
                    onSnooze(until);
                }
            }}
        >
            <input
                type="datetime-local"
                value={until}
                onChange={(event) => setUntil(event.target.value)}
                aria-label="Posponer hasta"
                className="rounded-xl border border-line bg-surface px-3 py-1 text-[13px] text-ink"
            />
            <Button type="submit" size="small">
                Posponer
            </Button>
        </form>
    );
}
