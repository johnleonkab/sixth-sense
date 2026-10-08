import ActionBar from '@/actions/action-bar';
import type { ActionState } from '@/actions/types';
import { useCanvas } from '@/canvas/canvas-context';
import CanvasFrame from '@/canvas/canvas-frame';
import { fileSize, personLabel, type MailPerson } from '@/canvas/mail/types';
import type { CanvasComponent } from '@/canvas/types';

type MailDraftProps = {
    action: ActionState;
    mode: 'new' | 'reply' | 'reply_all' | 'forward';
    from: string;
    to: MailPerson[];
    cc: MailPerson[];
    subject: string;
    /** What the person is saying, highlighted. */
    message: string;
    /** The quoted reply or the forwarded email, below it. */
    trail: string;
    attachments: { name: string; size: number }[];
};

const modeLabel: Record<MailDraftProps['mode'], string> = {
    new: 'Email nuevo',
    reply: 'Respuesta',
    reply_all: 'Respuesta a todos',
    forward: 'Reenvío',
};

function People({ label, people }: { label: string; people: MailPerson[] }) {
    if (people.length === 0) {
        return null;
    }

    return (
        <div className="flex gap-3 border-b border-line py-1.5">
            <span className="w-12 flex-none text-muted">{label}</span>
            <span className="min-w-0 flex-1">
                {people.map((person, i) => (
                    <span key={i}>
                        {i > 0 && ', '}
                        <span className="text-ink">{personLabel(person)}</span>
                        {person.name && person.email && <span className="text-muted"> &lt;{person.email}&gt;</span>}
                    </span>
                ))}
            </span>
        </div>
    );
}

/**
 * An email waiting for the person's "sí": who, what and from which account.
 * Nothing has been sent while it shows as a draft.
 */
const MailDraft: CanvasComponent<MailDraftProps> = ({ index, ...props }) => {
    const status = useCanvas().actions.actions[props.action.id]?.status ?? props.action.status;
    const sent = status === 'executed';

    return (
        <CanvasFrame index={index} title={props.action.label} source={`Gmail · ${modeLabel[props.mode]}`}>
            <p className={`-mt-1 mb-3 text-[12.5px] ${sent ? 'text-ok' : 'text-muted'}`}>
                {sent ? 'Enviado' : 'Borrador · aún no se ha enviado'}
            </p>

            <div className="mb-4 text-[13px]">
                <div className="flex gap-3 border-y border-line py-1.5">
                    <span className="w-12 flex-none text-muted">De</span>
                    <span className="text-ink">{props.from}</span>
                </div>
                <People label="Para" people={props.to} />
                <People label="CC" people={props.cc} />
                <div className="flex gap-3 border-b border-line py-1.5">
                    <span className="w-12 flex-none text-muted">Asunto</span>
                    <span className="font-medium text-ink">{props.subject}</span>
                </div>
            </div>

            {props.message && (
                <div className="rounded-[10px] border border-dashed border-accent bg-accent-soft px-3.5 py-3 text-[14px] leading-[1.6] whitespace-pre-wrap">
                    {props.message}
                </div>
            )}

            {props.trail && (
                <details className="mt-3 text-[13px] text-muted" open={!props.message}>
                    <summary className="cursor-pointer select-none hover:text-ink">
                        {props.mode === 'forward' ? 'Email reenviado' : 'Email citado'}
                    </summary>
                    <p className="mt-2 max-h-[260px] overflow-auto border-l-2 border-line pl-3 leading-[1.55] whitespace-pre-wrap">{props.trail}</p>
                </details>
            )}

            {props.attachments.length > 0 && (
                <ul aria-label="Adjuntos" className="mt-3 flex flex-wrap gap-2">
                    {props.attachments.map((attachment) => (
                        <li key={attachment.name} className="flex items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-[12.5px]">
                            <span className="max-w-[220px] truncate">{attachment.name}</span>
                            <span className="text-faint">{fileSize(attachment.size)}</span>
                        </li>
                    ))}
                </ul>
            )}

            <ActionBar initial={props.action} doneLabel="Email enviado" linkLabel="Ver en Gmail" verb="enviar" />
        </CanvasFrame>
    );
};

export default MailDraft;
