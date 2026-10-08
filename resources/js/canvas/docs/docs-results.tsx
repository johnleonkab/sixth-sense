import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { personName, type DocumentResultsProps } from '@/canvas/docs/types';
import { relativeDate } from '@/canvas/mail/types';
import type { CanvasComponent } from '@/canvas/types';

const docsSearchLabels: Record<string, (value: string) => string> = {
    name: (value) => `«${value}»`,
    text: (value) => `contiene «${value}»`,
    owner: (value) => `de ${value}`,
    modified_after: (value) => `editado desde ${value}`,
    modified_before: (value) => `editado hasta ${value}`,
    created_after: (value) => `creado desde ${value}`,
    created_before: (value) => `creado hasta ${value}`,
};

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/**
 * Documents found, as a numbered table: name, owner, created and last edited.
 */
const DocsResults: CanvasComponent<DocumentResultsProps> = ({ index, ...props }) => {
    const { runTool } = useCanvas();
    const chips = Object.entries(props.search).map(([key, value]) => ({ key, label: docsSearchLabels[key]?.(value) ?? value }));
    const columns = 'grid grid-cols-[26px_minmax(0,1fr)_86px] gap-3 md:grid-cols-[26px_minmax(0,1fr)_170px_110px_190px]';

    return (
        <CanvasFrame index={index} title={props.title} source={props.accounts.length > 1 ? `Docs · ${props.accounts.length} cuentas` : 'Docs'}>
            {chips.length > 0 && (
                <ul aria-label="Búsqueda" className="-mt-1 mb-3 flex flex-wrap gap-1.5">
                    {chips.map((chip) => (
                        <li key={chip.key} className="rounded-full bg-soft px-2.5 py-0.5 text-[12px]">
                            {chip.label}
                        </li>
                    ))}
                </ul>
            )}

            {props.results.length === 0 ? (
                <p className="text-[13px] text-muted">No he encontrado ningún documento.</p>
            ) : (
                <div role="table" aria-label="Documentos" className="-mx-2.5 text-[13px]">
                    <div role="row" className={`${columns} px-2.5 pb-2 text-[11.5px] text-faint`}>
                        <span />
                        <span role="columnheader">Nombre</span>
                        <span role="columnheader" className="hidden md:block">
                            Creador
                        </span>
                        <span role="columnheader" className="hidden md:block">
                            Creado
                        </span>
                        <span role="columnheader">Última edición</span>
                    </div>

                    {props.results.map((result) => (
                        <button
                            key={result.ref}
                            role="row"
                            type="button"
                            onClick={() => runTool('open_document', { document: result.ref })}
                            className={`${columns} w-full items-center rounded-[10px] border-t border-line px-2.5 py-2.5 text-left first-of-type:border-t-0 hover:bg-soft`}
                        >
                            <span className="grid size-[22px] place-items-center rounded-full border border-line font-mono text-[11px] text-muted">
                                {result.number}
                            </span>
                            <span role="cell" className="min-w-0">
                                <span className="flex items-center gap-2">
                                    <span aria-hidden className="grid size-4 flex-none place-items-center rounded-[3px] bg-accent text-[9px] font-semibold text-accent-ink">
                                        D
                                    </span>
                                    <b className="truncate font-medium">{result.title}</b>
                                </span>
                                {props.accounts.length > 1 && <span className="block truncate pl-6 text-[11.5px] text-faint">{result.account}</span>}
                            </span>
                            <span role="cell" className="hidden truncate text-muted md:block">
                                {personName(result.owner)}
                            </span>
                            <span role="cell" className="hidden font-mono text-[11.5px] text-muted md:block">
                                {relativeDate(result.created_at, browserTimezone)}
                            </span>
                            <span role="cell" className="min-w-0 text-muted">
                                <span className="font-mono text-[11.5px] text-ink">{relativeDate(result.modified_at, browserTimezone)}</span>
                                {result.modified_by && <span className="hidden truncate md:inline"> · {personName(result.modified_by)}</span>}
                            </span>
                        </button>
                    ))}
                </div>
            )}

            {props.may_have_more && props.results.length > 0 && (
                <p className="mt-3 text-[12px] text-faint">Puede haber más. Acota por nombre, creador o fechas.</p>
            )}
            {props.failed_accounts.length > 0 && (
                <p className="mt-4 rounded-lg bg-warn-soft px-3 py-2 text-[12.5px] text-warn">
                    No he podido buscar en {props.failed_accounts.join(', ')}.
                </p>
            )}
        </CanvasFrame>
    );
};

export default DocsResults;
