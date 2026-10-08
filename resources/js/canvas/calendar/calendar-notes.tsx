import type { CalendarCanvasProps } from '@/canvas/calendar/types';

/**
 * Accounts that could not be read, so a gap in the calendar is never silent.
 */
export default function CalendarNotes({ props }: { props: CalendarCanvasProps }) {
    if (props.failed_accounts.length === 0) {
        return null;
    }

    return (
        <p className="mt-4 rounded-lg bg-warn-soft px-3 py-2 text-[12.5px] text-warn">
            No he podido leer {props.failed_accounts.join(', ')}. Revísalo en Configuración.
        </p>
    );
}
