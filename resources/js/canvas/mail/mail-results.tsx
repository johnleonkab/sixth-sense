import CanvasFrame from '@/canvas/canvas-frame';
import { initials, personLabel, relativeDate, searchChips, type MailResult, type MailResultsProps } from '@/canvas/mail/types';
import type { CanvasComponent } from '@/canvas/types';

function ResultRow({ result, timezone, showAccount }: { result: MailResult; timezone: string; showAccount: boolean }) {
    return (
        <li className="relative grid grid-cols-[26px_minmax(0,1fr)] gap-3 rounded-[10px] px-2.5 py-3 hover:bg-soft [&+&]:before:absolute [&+&]:before:top-0 [&+&]:before:right-2.5 [&+&]:before:left-12 [&+&]:before:border-t [&+&]:before:border-line">
            <span
                className={`mt-px grid size-[22px] place-items-center rounded-full border font-mono text-[11px] ${result.unread ? 'border-accent bg-accent text-accent-ink' : 'border-line text-muted'}`}
                aria-label={`Resultado ${result.number}${result.unread ? ', sin leer' : ''}`}
            >
                {result.number}
            </span>

            <div className="min-w-0">
                <div className="flex items-baseline gap-2 text-[13px]">
                    <span
                        aria-hidden
                        className="grid size-5 flex-none translate-y-[3px] place-items-center rounded-full bg-soft text-[9px] font-medium text-muted"
                    >
                        {initials(result.from)}
                    </span>
                    <b className={`truncate ${result.unread ? 'font-semibold' : 'font-medium'}`}>{personLabel(result.from)}</b>
                    {showAccount && <span className="truncate text-[11.5px] text-faint">{result.account}</span>}
                    <time className="ml-auto flex-none font-mono text-[11px] text-faint">{relativeDate(result.date, timezone)}</time>
                </div>

                <p className={`mt-0.5 truncate text-[13.5px] ${result.unread ? 'text-ink' : 'text-ink/85'}`}>{result.subject}</p>

                <p className="mt-1 line-clamp-2 text-[13px] text-muted">
                    {result.excerpt ? (
                        <>
                            {result.excerpt.before}
                            <mark className="rounded-[3px] bg-hl px-0.5 text-ink">{result.excerpt.match}</mark>
                            {result.excerpt.after}
                        </>
                    ) : (
                        result.snippet
                    )}
                </p>
            </div>
        </li>
    );
}

/**
 * Search results, numbered so the person can say "abre el 2", each showing
 * where the search matched.
 */
const MailResults: CanvasComponent<MailResultsProps> = ({ index, ...props }) => {
    const chips = searchChips(props.search);
    const showAccount = props.accounts.length > 1;

    return (
        <CanvasFrame index={index} title={props.title} source={showAccount ? `Gmail · ${props.accounts.length} cuentas` : 'Gmail'}>
            {chips.length > 0 && (
                <ul aria-label="Búsqueda" className="-mt-1 mb-3 flex flex-wrap gap-1.5">
                    {chips.map((chip) => (
                        <li
                            key={chip.key}
                            className={`rounded-full bg-soft px-2.5 py-0.5 text-[12px] ${chip.key === 'pattern' ? 'font-mono text-accent' : 'text-ink'}`}
                        >
                            {chip.label}
                        </li>
                    ))}
                </ul>
            )}

            {props.results.length === 0 ? (
                <p className="text-[13px] text-muted">
                    No he encontrado ningún email.
                    {props.scanned > 0 && ` Revisé los ${props.scanned} más recientes que encajan con el resto de filtros.`}
                </p>
            ) : (
                <ol className="-mx-2.5">
                    {props.results.map((result) => (
                        <ResultRow key={result.ref} result={result} timezone={props.timezone} showAccount={showAccount} />
                    ))}
                </ol>
            )}

            {props.may_have_more && props.results.length > 0 && (
                <p className="mt-3 text-[12px] text-faint">Puede haber más. Acota por remitente o fechas para afinar.</p>
            )}

            {props.failed_accounts.length > 0 && (
                <p className="mt-4 rounded-lg bg-warn-soft px-3 py-2 text-[12.5px] text-warn">
                    No he podido buscar en {props.failed_accounts.join(', ')}. Revísalo en Configuración.
                </p>
            )}
        </CanvasFrame>
    );
};

export default MailResults;
