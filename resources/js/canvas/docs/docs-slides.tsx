import CanvasFrame from '@/canvas/canvas-frame';
import type { CanvasComponent } from '@/canvas/types';

type SlidesProps = {
    title: string;
    url: string | null;
    account: string;
    slides: { number: number; src: string }[];
};

/**
 * The slides of a presentation, one image after another.
 */
const DocsSlides: CanvasComponent<SlidesProps> = ({ index, title, url, account, slides }) => (
    <CanvasFrame
        index={index}
        title={title}
        source={`Slides · ${account}`}
        aside={
            url && (
                <a href={url} target="_blank" rel="noreferrer noopener" className="text-accent hover:underline">
                    Abrir en Slides ↗
                </a>
            )
        }
    >
        <ol className="grid gap-4">
            {slides.map((slide) => (
                <li key={slide.number}>
                    <p className="mb-1.5 font-mono text-[11px] text-faint">{slide.number}</p>
                    <img src={slide.src} alt={`Diapositiva ${slide.number}`} className="w-full rounded-lg border border-line" />
                </li>
            ))}
        </ol>
    </CanvasFrame>
);

export default DocsSlides;
