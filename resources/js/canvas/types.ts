import type { ComponentType } from 'react';

/**
 * Something the assistant asked to put on screen: a registered canvas name and
 * the data it renders. Sent by Laravel, never HTML.
 */
export type CanvasDirective = {
    view: string;
    props: Record<string, unknown>;
    /** A centred column, or the full width for large content such as a document. */
    layout?: 'focus' | 'wide';
};

/**
 * A canvas is a preconfigured way of presenting one kind of data (a day of
 * events, an agenda, an email…). `index` is its number on screen, so the
 * person can point at it by voice ("abre el 2").
 */
export type CanvasComponent<Props> = ComponentType<Props & { index: number }>;
