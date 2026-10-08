import { useEffect, useRef, useState } from 'react';

import CanvasFrame from '@/canvas/canvas-frame';
import { nextZoom, ToolbarButton, type FileProps } from '@/canvas/files/viewer-toolbar';
import type { CanvasComponent } from '@/canvas/types';
import { registerViewer } from '@/canvas/viewers';

/**
 * An image someone sent, fitted to the space; zoom from the toolbar, by
 * clicking it or by voice, and scroll around it when it is larger.
 */
const ImageViewer: CanvasComponent<FileProps> = ({ index, ...props }) => {
    const [zoom, setZoom] = useState(1);
    const [failed, setFailed] = useState(false);
    const zoomRef = useRef(zoom);
    zoomRef.current = zoom;

    useEffect(
        () =>
            registerViewer(index, async (args) => {
                switch (args.action) {
                    case 'zoom_in':
                    case 'zoom_out':
                    case 'fit': {
                        const next = args.action === 'fit' ? 1 : nextZoom(zoomRef.current, args.action === 'zoom_in' ? 1 : -1);
                        setZoom(next);

                        return { zoom_percent: Math.round(next * 100) };
                    }
                    default:
                        return { error: 'Es una imagen: solo se puede ampliar, reducir o ajustar. No tiene páginas ni puedo leer su texto.' };
                }
            }),
        [index],
    );

    return (
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
            <div className="mb-3 flex items-center justify-end gap-1 text-[12.5px] text-muted">
                <ToolbarButton label="Reducir" disabled={zoom <= 0.5} onClick={() => setZoom(nextZoom(zoom, -1))}>
                    −
                </ToolbarButton>
                <span className="min-w-[44px] text-center font-mono tabular-nums">{Math.round(zoom * 100)}%</span>
                <ToolbarButton label="Ampliar" onClick={() => setZoom(nextZoom(zoom, 1))}>
                    +
                </ToolbarButton>
                <ToolbarButton label="Ajustar" disabled={zoom === 1} onClick={() => setZoom(1)}>
                    Ajustar
                </ToolbarButton>
            </div>

            <div className="grid max-h-[68vh] place-items-center overflow-auto rounded-xl bg-soft">
                {failed ? (
                    <p className="p-10 text-[13px] text-muted">No he podido cargar la imagen.</p>
                ) : (
                    <img
                        src={props.src}
                        alt={props.name}
                        onError={() => setFailed(true)}
                        onClick={() => setZoom(zoom === 1 ? 2 : 1)}
                        style={zoom === 1 ? undefined : { width: `${zoom * 100}%`, maxWidth: 'none', maxHeight: 'none' }}
                        className={`max-h-[68vh] max-w-full object-contain ${zoom === 1 ? 'cursor-zoom-in' : 'cursor-zoom-out'}`}
                    />
                )}
            </div>
        </CanvasFrame>
    );
};

export default ImageViewer;
