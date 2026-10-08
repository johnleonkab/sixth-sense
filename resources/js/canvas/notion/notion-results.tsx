import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { relativeDate } from '@/canvas/mail/types';
import type { NotionResultsProps } from '@/canvas/notion/types';
import type { CanvasComponent } from '@/canvas/types';

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/**
 * Notion pages, numbered so the person can say "abre la 2".
 */
const NotionResults: CanvasComponent<NotionResultsProps> = ({ index, title, pages }) => {
    const { runTool } = useCanvas();

    return (
        <CanvasFrame index={index} title={title} source="Notion">
            <ol className="-mx-2.5">
                {pages.map((page) => (
                    <li key={page.ref} className="border-t border-line first:border-t-0">
                        <button
                            type="button"
                            onClick={() => runTool('open_notion_page', { page: page.ref })}
                            className="grid w-full grid-cols-[26px_minmax(0,1fr)] items-center gap-3 rounded-[10px] px-2.5 py-3 text-left hover:bg-soft"
                        >
                            <span className="grid size-[22px] place-items-center rounded-full border border-line font-mono text-[11px] text-muted">{page.number}</span>
                            <span className="min-w-0">
                                <span className="flex items-baseline gap-2">
                                    <b className="truncate text-[14px] font-medium">
                                        {page.icon && <span className="mr-1.5">{page.icon}</span>}
                                        {page.title}
                                    </b>
                                    {page.edited_at && <time className="ml-auto flex-none font-mono text-[11px] text-faint">{relativeDate(page.edited_at, browserTimezone)}</time>}
                                </span>
                                <span className="mt-0.5 block truncate text-[12.5px] text-muted">
                                    {page.parent ? `En ${page.parent} · ` : ''}
                                    {page.workspace}
                                </span>
                            </span>
                        </button>
                    </li>
                ))}
            </ol>
        </CanvasFrame>
    );
};

export default NotionResults;
