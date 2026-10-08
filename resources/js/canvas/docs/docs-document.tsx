import CanvasFrame from '@/canvas/canvas-frame';
import type { Block, DocumentCommentThread, DocumentProps, Run } from '@/canvas/docs/types';
import type { CanvasComponent } from '@/canvas/types';

function Runs({ runs }: { runs: Run[] }) {
    return (
        <>
            {runs.map((run, i) => {
                let node: React.ReactNode = run.text;

                if (run.bold) node = <strong className="font-semibold">{node}</strong>;
                if (run.italic) node = <em>{node}</em>;
                if (run.underline) node = <u>{node}</u>;
                if (run.strike) node = <s>{node}</s>;
                if (run.code) node = <code className="rounded bg-soft px-1 font-mono text-[0.9em]">{node}</code>;
                if (run.link) {
                    node = (
                        <a href={run.link} target="_blank" rel="noreferrer noopener" className="text-accent underline decoration-accent/40 underline-offset-2">
                            {node}
                        </a>
                    );
                }

                return <span key={i}>{node}</span>;
            })}
        </>
    );
}

const headingClass: Record<number, string> = {
    0: 'mt-2 mb-4 text-[28px] font-medium tracking-[-0.02em]',
    1: 'mt-7 mb-3 text-[22px] font-medium tracking-[-0.015em]',
    2: 'mt-6 mb-2 text-[18px] font-medium tracking-[-0.01em]',
    3: 'mt-5 mb-2 text-[15.5px] font-semibold',
    4: 'mt-4 mb-1.5 text-[15px] font-semibold',
    5: 'mt-4 mb-1.5 text-[14.5px] font-semibold text-muted',
    6: 'mt-1 mb-4 text-[17px] text-muted',
};

/** Numbers for ordered lists: a counter per nesting level, reset after other blocks. */
function listMarkers(blocks: Block[]): (string | null)[] {
    const counters: number[] = [];

    return blocks.map((block) => {
        if (block.type !== 'list_item') {
            counters.length = 0;

            return null;
        }

        counters.length = block.level + 1;
        counters[block.level] = (counters[block.level] ?? 0) + 1;

        return block.ordered ? `${counters[block.level]}.` : ['•', '◦', '▪'][block.level % 3];
    });
}

export function Blocks({ blocks }: { blocks: Block[] }) {
    const markers = listMarkers(blocks);

    return (
        <>
            {blocks.map((block, i) => {
                switch (block.type) {
                    case 'heading': {
                        const Tag = (block.level === 0 ? 'h2' : `h${Math.min(block.level + 2, 6)}`) as 'h2';

                        return (
                            <Tag key={i} className={headingClass[block.level] ?? headingClass[3]}>
                                <Runs runs={block.runs} />
                            </Tag>
                        );
                    }
                    case 'list_item':
                        return (
                            <div key={i} className="my-1 flex gap-2.5" style={{ paddingLeft: block.level * 22 }}>
                                <span className="w-5 flex-none text-right text-muted tabular-nums">{block.checked === undefined ? markers[i] : block.checked ? '☑' : '☐'}</span>
                                <p className={`min-w-0 ${block.checked ? 'text-muted line-through' : ''}`}>
                                    <Runs runs={block.runs} />
                                </p>
                            </div>
                        );
                    case 'table':
                        return (
                            <div key={i} className="my-4 overflow-x-auto">
                                <table className="w-full border-collapse text-[13.5px]">
                                    <tbody>
                                        {block.rows.map((row, r) => (
                                            <tr key={r}>
                                                {row.map((cell, c) => (
                                                    <td key={c} className="border border-line px-3 py-2 align-top [&_p]:my-0">
                                                        <Blocks blocks={cell} />
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        );
                    case 'quote':
                        return (
                            <blockquote key={i} className="my-3 border-l-[3px] border-line pl-4 text-ink/85">
                                <Runs runs={block.runs} />
                            </blockquote>
                        );
                    case 'code':
                        return (
                            <pre key={i} className="my-3 overflow-x-auto rounded-lg bg-soft px-4 py-3 font-mono text-[13px] leading-[1.6]">
                                {block.text}
                            </pre>
                        );
                    case 'rule':
                        return <hr key={i} className="my-6 border-line" />;
                    case 'image':
                        return (
                            <p key={i} className="my-3 rounded-lg border border-dashed border-line px-3 py-2 text-[12.5px] text-faint">
                                Imagen
                            </p>
                        );
                    case 'truncated':
                        return (
                            <p key={i} className="mt-6 text-[12.5px] text-faint">
                                El documento sigue. Ábrelo en Docs para verlo entero.
                            </p>
                        );
                    default:
                        return block.runs.length === 0 ? (
                            <div key={i} className="h-3" />
                        ) : (
                            <p key={i} className="my-2.5">
                                <Runs runs={block.runs} />
                            </p>
                        );
                }
            })}
        </>
    );
}

const commentTime = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

/** Open comment threads beside the document, numbered for "responde al 2". */
export function Comments({ comments }: { comments: DocumentCommentThread[] }) {
    return (
        <aside aria-label="Comentarios" className="min-w-0 border-t border-line pt-4 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
            <h4 className="mb-3 text-[12px] font-medium tracking-wide text-muted uppercase">Comentarios · {comments.length}</h4>
            <ol className="space-y-3">
                {comments.map((comment) => (
                    <li key={comment.id} className={`rounded-xl border p-3 text-[13px] ${comment.awaits_user ? 'border-accent/50 bg-accent-soft/40' : 'border-line'}`}>
                        <div className="mb-1 flex items-center gap-2">
                            <span className="grid size-[18px] flex-none place-items-center rounded-full border border-line font-mono text-[10px] text-muted">{comment.number}</span>
                            <b className="truncate font-medium">{comment.mine ? 'Tú' : comment.author}</b>
                            <time className="ml-auto flex-none font-mono text-[10.5px] text-faint">{commentTime.format(new Date(comment.created_at))}</time>
                        </div>
                        {comment.quote && <p className="mb-1 line-clamp-2 border-l-2 border-hl pl-2 text-[12px] text-muted italic">«{comment.quote}»</p>}
                        <p className="whitespace-pre-wrap">{comment.content}</p>
                        {comment.replies.length > 0 && (
                            <ul className="mt-2 space-y-1.5 border-l border-line pl-3">
                                {comment.replies.map((reply) => (
                                    <li key={reply.id}>
                                        <b className="text-[12px] font-medium">{reply.mine ? 'Tú' : reply.author}</b>
                                        <p className="whitespace-pre-wrap text-ink/90">{reply.content}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {comment.awaits_user && <p className="mt-2 text-[11.5px] text-accent">Espera tu respuesta</p>}
                    </li>
                ))}
            </ol>
        </aside>
    );
}

/**
 * A Google Doc at full width, with its basic formatting: headings, emphasis,
 * links, lists and tables, and its open comments beside it. Nothing from the
 * document is rendered as HTML.
 */
const DocsDocument: CanvasComponent<DocumentProps> = ({ index, comments = [], ...props }) => (
    <CanvasFrame
        index={index}
        title={props.title}
        source="Docs"
        aside={
            props.url && (
                <a href={props.url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                    Abrir en Docs ↗
                </a>
            )
        }
    >
        <div className={comments.length > 0 ? 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]' : ''}>
            <div className="mx-auto w-full max-w-[720px] pb-4 text-[15px] leading-[1.7] break-words">
                <Blocks blocks={props.blocks} />
            </div>
            {comments.length > 0 && <Comments comments={comments} />}
        </div>
    </CanvasFrame>
);

export default DocsDocument;
