import CanvasFrame from '@/canvas/canvas-frame';
import { fileSize } from '@/canvas/mail/types';
import type { CanvasComponent } from '@/canvas/types';

type MediaProps = {
    name: string;
    kind: 'image' | 'video' | 'audio' | 'pdf' | 'other';
    mime_type: string | null;
    size: number;
    source: string;
    /** Served by Laravel with the user's credentials. */
    src: string;
};

/**
 * Any file at full width: images, PDFs, video and audio are shown here;
 * anything else can be downloaded.
 */
const Media: CanvasComponent<MediaProps> = ({ index, ...props }) => (
    <CanvasFrame
        index={index}
        title={props.name}
        source={props.source}
        aside={
            <a href={props.src} download={props.name} className="text-accent hover:underline">
                Descargar
            </a>
        }
    >
        <div className="grid place-items-center overflow-hidden rounded-xl bg-soft">
            {props.kind === 'image' && <img src={props.src} alt={props.name} className="max-h-[70vh] w-auto max-w-full object-contain" />}
            {props.kind === 'video' && <video src={props.src} controls className="max-h-[70vh] w-full" />}
            {props.kind === 'audio' && <audio src={props.src} controls className="m-6 w-full max-w-[520px]" />}
            {props.kind === 'pdf' && <iframe src={props.src} title={props.name} className="h-[70vh] w-full border-0 bg-surface" />}
            {props.kind === 'other' && (
                <div className="p-10 text-center text-[13px] text-muted">
                    <p>Este tipo de archivo no se puede ver aquí.</p>
                    <a href={props.src} download={props.name} className="mt-2 inline-block font-medium text-accent hover:underline">
                        Descargar {props.name} ({fileSize(props.size)})
                    </a>
                </div>
            )}
        </div>
    </CanvasFrame>
);

export default Media;
