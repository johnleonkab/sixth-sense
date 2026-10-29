import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

type SheetProps = {
    title: string;
    url: string | null;
    account: string;
    sheet: string;
    rows: string[][];
};

/**
 * The first rows of a Google Sheet, enough to see what the grid holds.
 */
const DocsSheet: CanvasComponent<SheetProps> = ({ index, title, url, account, sheet, rows }) => (
    <CanvasFrame
        index={index}
        title={title}
        source={`Sheets · ${account}`}
        aside={
            url && (
                <a href={url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                    Abrir en Sheets ↗
                </a>
            )
        }
    >
        <p className="mb-3 text-[12.5px] text-muted">{sheet}</p>
        {rows.length === 0 ? (
            <p className="text-[13px] text-muted">Esta hoja no tiene celdas en el primer tramo.</p>
        ) : (
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-[13px]">
                    <tbody>
                        {rows.map((row, r) => (
                            <tr key={r}>
                                {row.map((cell, c) => (
                                    <td key={c} className={`border border-line px-2.5 py-1.5 align-top whitespace-nowrap ${r === 0 ? 'bg-soft font-medium' : ''}`}>
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        )}
    </CanvasFrame>
);

export default DocsSheet;
