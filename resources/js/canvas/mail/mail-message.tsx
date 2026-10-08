import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import DocumentChip from '@/canvas/docs/document-chip';
import { fileSize, initials, personLabel, type MailMessageProps, type MailPerson } from '@/canvas/mail/types';
import type { CanvasComponent } from '@/canvas/types';

const dateFormat = new Intl.DateTimeFormat('es-ES', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'UTC',
});

/** The ISO date is already local: format its wall-clock time as is. */
function longDate(iso: string): string {
    const text = dateFormat.format(new Date(`${iso.slice(0, 19)}Z`));

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function people(list: MailPerson[]): string {
    return list.map(personLabel).join(', ');
}

/** An attachment opens in the viewer that suits it (PDF, image…). */
function Attachment({ emailRef, position, attachment }: { emailRef: string; position: number; attachment: MailMessageProps['attachments'][number] }) {
    const { runTool } = useCanvas();

    return (
        <button
            type="button"
            title={`Abrir ${attachment.name}`}
            onClick={() => runTool('open_email_attachment', { email_id: emailRef, attachment: String(position + 1) })}
            className="flex items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-[12.5px] hover:bg-soft"
        >
            <span aria-hidden className="font-mono text-[10px] text-muted uppercase">
                {attachment.name.split('.').pop()?.slice(0, 4)}
            </span>
            <span className="max-w-[220px] truncate">{attachment.name}</span>
            <span className="text-faint">{fileSize(attachment.size)}</span>
        </button>
    );
}

/**
 * One email, as clean text. Matches of the search are highlighted.
 */
const MailMessage: CanvasComponent<MailMessageProps> = ({ index, ...props }) => (
    <CanvasFrame
        index={index}
        title={props.subject}
        source="Gmail"
        aside={
            props.url && (
                <a href={props.url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                    Abrir en Gmail ↗
                </a>
            )
        }
    >
        <div className="mb-4 flex items-start gap-3 border-b border-line pb-4">
            <span aria-hidden className="grid size-8 flex-none place-items-center rounded-full bg-soft text-[11px] font-medium text-muted">
                {initials(props.from)}
            </span>
            <div className="min-w-0 flex-1 text-[13px]">
                <p className="truncate">
                    <b className="font-medium">{personLabel(props.from)}</b>
                    {props.from?.name && props.from.email && <span className="text-muted"> &lt;{props.from.email}&gt;</span>}
                </p>
                {props.to.length > 0 && <p className="truncate text-muted">Para: {people(props.to)}</p>}
                {props.cc.length > 0 && <p className="truncate text-muted">CC: {people(props.cc)}</p>}
            </div>
            <time className="flex-none text-[12px] text-muted">{longDate(props.date)}</time>
        </div>

        <div className="max-w-[640px] text-[14px] leading-[1.6] break-words whitespace-pre-wrap">
            {props.body.map((segment, i) =>
                segment.match ? (
                    <mark key={i} className="rounded-[3px] bg-hl px-0.5 text-ink">
                        {segment.text}
                    </mark>
                ) : (
                    <span key={i}>{segment.text}</span>
                ),
            )}
        </div>

        {props.documents.length > 0 && (
            <div className="mt-5 border-t border-line pt-4">
                <p className="mb-2 text-[12px] text-muted">Documentos enlazados</p>
                <div className="flex flex-wrap gap-2">
                    {props.documents.map((document) => (
                        <DocumentChip key={document.url} url={document.url} title={document.title} />
                    ))}
                </div>
            </div>
        )}

        {props.attachments.length > 0 && (
            <ul aria-label="Adjuntos" className="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
                {props.attachments.map((attachment, position) => (
                    <li key={`${position}-${attachment.name}`}>
                        <Attachment emailRef={props.ref} position={position} attachment={attachment} />
                    </li>
                ))}
            </ul>
        )}
    </CanvasFrame>
);

export default MailMessage;
