import CanvasFrame from '@/canvas/canvas-frame';
import type { Block, DocumentProps, Run } from '@/canvas/docs/types';
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

function Blocks({ blocks }: { blocks: Block[] }) {
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
                                <span className="w-5 flex-none text-right text-muted tabular-nums">{markers[i]}</span>
                                <p className="min-w-0">
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

/**
 * A Google Doc at full width, with its basic formatting: headings, emphasis,
 * links, lists and tables. Nothing from the document is rendered as HTML.
 */
const DocsDocument: CanvasComponent<DocumentProps> = ({ index, ...props }) => (
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
        <div className="mx-auto max-w-[720px] pb-4 text-[15px] leading-[1.7] break-words">
            <Blocks blocks={props.blocks} />
        </div>
    </CanvasFrame>
);

export default DocsDocument;
