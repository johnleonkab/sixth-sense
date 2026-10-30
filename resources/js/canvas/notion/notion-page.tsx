import { useState } from 'react';

import CanvasFrame from '@/canvas/canvas-frame';
import { Blocks, Comments } from '@/canvas/docs/docs-document';
import type { NotionPageProps } from '@/canvas/notion/types';
import type { CanvasComponent } from '@/canvas/types';
import { Button } from '@/components/button';

/** Copies a link and says so for a moment. */
function CopyLink({ url, label }: { url: string; label: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            size="extra-small"
            onClick={() =>
                void navigator.clipboard.writeText(url).then(() => {
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1800);
                })
            }
        >
            {copied ? 'Copiado' : label}
        </Button>
    );
}

/**
 * A Notion page at full width, with its open comments beside it and its links
 * to share. Nothing from the page is rendered as HTML.
 */
const NotionPage: CanvasComponent<NotionPageProps> = ({ index, ...props }) => (
    <CanvasFrame
        index={index}
        title={`${props.icon ? `${props.icon} ` : ''}${props.title}`}
        source={`Notion · ${props.workspace}`}
        aside={
            <>
                {props.url && <CopyLink url={props.url} label="Copiar enlace" />}
                {props.public_url && <CopyLink url={props.public_url} label="Copiar enlace público" />}
                {props.url && (
                    <a href={props.url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                        Abrir en Notion ↗
                    </a>
                )}
            </>
        }
    >
        <div className={props.comments.length > 0 ? 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]' : ''}>
            <div className="mx-auto w-full max-w-[720px] pb-4 text-[15px] leading-[1.7] break-words">
                {props.blocks.length > 0 ? <Blocks blocks={props.blocks} /> : <p className="text-muted">Esta página está vacía.</p>}
            </div>
            {props.comments.length > 0 && <Comments comments={props.comments} />}
        </div>
    </CanvasFrame>
);

export default NotionPage;
