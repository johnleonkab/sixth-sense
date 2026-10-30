import { useIsQueryLoading, useStateField, useTriggerAction } from '@openuidev/react-lang';
import { useContext, useEffect, useState } from 'react';
import type { ComponentRenderProps } from '@openuidev/react-lang';

import { LiveItemsContext, openRef } from '@/canvas/openui/refs';
import { buildLibrary, top } from '@/canvas/openui/spec';

/**
 * How each OpenUI block looks on the canvas: the same tokens, type and
 * spacing as every other view. Charts are plain SVG.
 */
type Props<P> = ComponentRenderProps<P>;
type Cell = string | number;

const https = (url: string): boolean => /^https:\/\//i.test(url);

/** The canvas frame already shows the view's title. */
function View({ props, renderNode }: Props<{ title: string; children: unknown[] }>) {
    return <div className="grid gap-4">{renderNode(props.children)}</div>;
}

function Row({ props, renderNode }: Props<{ children: unknown[] }>) {
    return <div className="grid gap-3 sm:grid-flow-col sm:auto-cols-fr">{renderNode(props.children)}</div>;
}

function Card({ props, renderNode }: Props<{ title?: string; children: unknown[] }>) {
    return (
        <section className="grid gap-3 rounded-2xl border border-line bg-surface px-4 py-3.5">
            {props.title && <h4 className="text-[14px] font-medium">{props.title}</h4>}
            {renderNode(props.children)}
        </section>
    );
}

function Text({ props }: Props<{ text: string; tone?: 'default' | 'muted' | 'warn' }>) {
    if (props.tone === 'warn') {
        return <p className="rounded-lg bg-warn-soft px-3 py-2 text-[12.5px] text-warn">{props.text}</p>;
    }

    return <p className={`text-[14px] leading-[1.55] ${props.tone === 'muted' ? 'text-muted' : ''}`}>{props.text}</p>;
}

function Metric({ props }: Props<{ label: string; value: string; change?: string; hint?: string }>) {
    const down = props.change?.trim().startsWith('-');

    return (
        <div className="rounded-xl bg-soft px-3.5 py-3">
            <p className="text-[12.5px] text-muted">{props.label}</p>
            <p className="mt-0.5 flex items-baseline gap-2">
                <span className="text-[22px] font-medium tracking-[-0.02em] tabular-nums">{props.value}</span>
                {props.change && <span className={`text-[12.5px] tabular-nums ${down ? 'text-crit' : 'text-ok'}`}>{props.change}</span>}
            </p>
            {props.hint && <p className="mt-0.5 text-[12px] text-faint">{props.hint}</p>}
        </div>
    );
}

function Table({ props }: Props<{ columns: string[]; rows: Cell[][] }>) {
    return (
        <div className="overflow-x-auto rounded-xl border border-line">
            <table className="w-full text-left text-[13px]">
                <thead className="bg-soft text-muted">
                    <tr>
                        {props.columns.map((column, index) => (
                            <th key={index} scope="col" className="px-3 py-2 font-medium">
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {props.rows.map((row, rowIndex) => (
                        <tr key={rowIndex}>
                            {row.map((cell, cellIndex) => (
                                <td key={cellIndex} className={`px-3 py-2 ${typeof cell === 'number' ? 'text-right tabular-nums' : ''}`}>
                                    {typeof cell === 'number' ? cell.toLocaleString('es-ES') : cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function BarChart({ props }: Props<{ title?: string; labels: string[]; values: number[]; unit?: string }>) {
    const max = Math.max(1, ...props.values.map((value) => Math.abs(value)));

    return (
        <figure className="grid gap-2">
            {props.title && <figcaption className="text-[13px] font-medium">{props.title}</figcaption>}
            <ul className="grid gap-1.5">
                {props.labels.map((label, index) => {
                    const value = props.values[index] ?? 0;

                    return (
                        <li key={index} className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-2 text-[12.5px]">
                            <span className="truncate text-muted">{label}</span>
                            <span className="h-2 rounded-full bg-soft">
                                <span className="block h-2 rounded-full bg-accent" style={{ width: `${(Math.abs(value) / max) * 100}%` }} />
                            </span>
                            <span className="tabular-nums">
                                {value.toLocaleString('es-ES')}
                                {props.unit ? ` ${props.unit}` : ''}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </figure>
    );
}

function LineChart({ props }: Props<{ title?: string; labels: string[]; values: number[]; unit?: string }>) {
    const width = 600;
    const height = 160;
    const values = props.values.length > 0 ? props.values : [0];
    const max = Math.max(...values);
    const min = Math.min(...values);
    const span = max - min || 1;
    const step = values.length > 1 ? width / (values.length - 1) : 0;
    const points = values.map((value, index) => `${index * step},${height - 8 - ((value - min) / span) * (height - 16)}`).join(' ');
    const last = values[values.length - 1];

    return (
        <figure className="grid gap-2">
            <figcaption className="flex items-baseline justify-between gap-3 text-[13px]">
                <span className="font-medium">{props.title}</span>
                <span className="text-muted tabular-nums">
                    {last.toLocaleString('es-ES')}
                    {props.unit ? ` ${props.unit}` : ''}
                </span>
            </figcaption>
            <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="h-36 w-full" role="img" aria-label={props.title ?? 'Tendencia'}>
                <polyline points={points} fill="none" stroke="var(--color-accent)" strokeWidth="2.5" vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
            </svg>
            <div className="flex justify-between text-[11.5px] text-faint">
                <span>{props.labels[0]}</span>
                <span>{props.labels[props.labels.length - 1]}</span>
            </div>
        </figure>
    );
}

const SERIES = 6;

const format = (value: number) => value.toLocaleString('es-ES', { maximumFractionDigits: 1 });

/**
 * Parts of a whole: at most six segments (the rest folds into "Otros"), each
 * a validated series colour in fixed order, a 2px gap between them, the total
 * in the middle and a legend with value and share, so colour is never the
 * only way to tell them apart. With highlight, one segment in the first
 * series colour and the rest in grey.
 */
function DonutChart({ props }: Props<{ title?: string; labels: string[]; values: number[]; unit?: string; highlight?: string }>) {
    const [active, setActive] = useState<number | null>(null);
    const pairs = props.labels.map((label, index) => ({ label, value: Math.max(0, props.values[index] ?? 0) })).filter((pair) => pair.value > 0);
    const folded = top({ labels: pairs.map((pair) => pair.label), values: pairs.map((pair) => pair.value) }, SERIES);
    const total = folded.values.reduce((sum, value) => sum + value, 0);

    if (total === 0) {
        return <p className="text-[13px] text-muted">{props.title ? `${props.title}: ` : ''}sin datos.</p>;
    }

    const emphasis = props.highlight !== undefined && folded.labels.includes(props.highlight);
    const colour = (index: number) =>
        emphasis ? (folded.labels[index] === props.highlight ? 'var(--series-1)' : 'var(--muted)') : `var(--series-${index + 1})`;
    const radius = 48;
    const circumference = 2 * Math.PI * radius;
    const gap = folded.values.length > 1 ? 2 : 0;
    const share = (value: number) => Math.round((value / total) * 100);
    let start = 0;

    return (
        <figure className="grid gap-3">
            {props.title && <figcaption className="text-[13px] font-medium">{props.title}</figcaption>}
            <div className="grid items-center gap-4 sm:grid-cols-[150px_minmax(0,1fr)]">
                <svg
                    viewBox="0 0 120 120"
                    className="mx-auto size-[150px]"
                    role="img"
                    aria-label={`${props.title ?? 'Reparto'}: ${folded.labels.map((label, index) => `${label} ${share(folded.values[index])}%`).join(', ')}`}
                >
                    <g transform="rotate(-90 60 60)">
                        {folded.values.map((value, index) => {
                            const length = (value / total) * circumference;
                            const dash = Math.max(0.5, length - gap);
                            const offset = -start;
                            start += length;

                            return (
                                <circle
                                    key={folded.labels[index]}
                                    cx="60"
                                    cy="60"
                                    r={radius}
                                    fill="none"
                                    stroke={colour(index)}
                                    strokeOpacity={emphasis && folded.labels[index] !== props.highlight ? 0.45 : active === null || active === index ? 1 : 0.35}
                                    strokeWidth="16"
                                    strokeDasharray={`${dash} ${circumference - dash}`}
                                    strokeDashoffset={offset}
                                    onMouseEnter={() => setActive(index)}
                                    onMouseLeave={() => setActive(null)}
                                >
                                    <title>{`${folded.labels[index]}: ${format(value)}${props.unit ? ` ${props.unit}` : ''} (${share(value)} %)`}</title>
                                </circle>
                            );
                        })}
                    </g>
                    <text x="60" y="58" textAnchor="middle" className="fill-ink text-[17px] font-medium tabular-nums">
                        {format(active === null ? total : folded.values[active])}
                    </text>
                    <text x="60" y="74" textAnchor="middle" className="fill-muted text-[9px]">
                        {active === null ? (props.unit ?? 'total') : `${share(folded.values[active])} %`}
                    </text>
                </svg>
                <ul className="grid gap-1.5 text-[13px]">
                    {folded.labels.map((label, index) => (
                        <li
                            key={label}
                            onMouseEnter={() => setActive(index)}
                            onMouseLeave={() => setActive(null)}
                            className={`grid grid-cols-[10px_minmax(0,1fr)_auto_3.2rem] items-center gap-2 ${active !== null && active !== index ? 'opacity-60' : ''}`}
                        >
                            <span aria-hidden className="size-2.5 rounded-[3px]" style={{ background: colour(index) }} />
                            <span className="truncate">{label}</span>
                            <span className="tabular-nums">
                                {format(folded.values[index])}
                                {props.unit ? ` ${props.unit}` : ''}
                            </span>
                            <span className="text-right text-muted tabular-nums">{share(folded.values[index])} %</span>
                        </li>
                    ))}
                </ul>
            </div>
        </figure>
    );
}

function Image({ props }: Props<{ url: string; caption?: string }>) {
    if (!https(props.url)) {
        return null;
    }

    return (
        <figure className="grid gap-1.5">
            <img src={props.url} alt={props.caption ?? ''} loading="lazy" className="max-h-[420px] w-full rounded-xl border border-line object-contain" />
            {props.caption && <figcaption className="text-[12.5px] text-muted">{props.caption}</figcaption>}
        </figure>
    );
}

const kindLabel: Record<string, string> = { pdf: 'PDF', doc: 'Documento', sheet: 'Hoja', page: 'Página', link: 'Enlace' };

function Document({ props }: Props<{ title: string; url: string; kind?: string; note?: string }>) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-xl border border-line px-3.5 py-2.5">
            <div className="min-w-0">
                <p className="truncate text-[14px] font-medium">{props.title}</p>
                <p className="truncate text-[12.5px] text-muted">{[kindLabel[props.kind ?? 'link'], props.note].filter(Boolean).join(' · ')}</p>
            </div>
            {https(props.url) && (
                <a href={props.url} target="_blank" rel="noopener noreferrer" className="shrink-0 rounded-full border border-line px-3 py-1 text-[12.5px] hover:bg-soft">
                    Abrir
                </a>
            )}
        </div>
    );
}

function List({ props }: Props<{ items: string[]; ordered?: boolean }>) {
    const Tag = props.ordered ? 'ol' : 'ul';

    return (
        <Tag className={`grid gap-1 pl-5 text-[14px] leading-[1.5] ${props.ordered ? 'list-decimal' : 'list-disc'}`}>
            {props.items.map((item, index) => (
                <li key={index}>{item}</li>
            ))}
        </Tag>
    );
}

function Button({ props }: Props<{ label: string; action?: unknown }>) {
    const trigger = useTriggerAction();

    return (
        <button
            type="button"
            onClick={() => void trigger(props.label, undefined, props.action as Parameters<typeof trigger>[2])}
            className="rounded-full border border-line px-3.5 py-1.5 text-[13px] hover:bg-soft"
        >
            {props.label}
        </button>
    );
}

type Item = { n: number; title: string; eyebrow?: string; snippet?: string; match?: string; meta?: string; status?: string; highlight?: boolean; ref?: string };

/** Filter tabs: the chosen one is a $variable, and the queries that use it load again. */
function Tabs({ props }: Props<{ options: { value: string; label: string }[]; value: unknown }>) {
    const field = useStateField<string>('tabs', props.value as string);

    return (
        <div role="tablist" aria-label="Filtrar" className="flex flex-wrap gap-1.5">
            {props.options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="tab"
                    aria-selected={field.value === option.value}
                    onClick={() => field.setValue(option.value)}
                    className={`rounded-full px-3 py-1 text-[12.5px] transition-colors ${field.value === option.value ? 'bg-ink text-bg' : 'border border-line hover:bg-soft'}`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

/** The snippet with where the search matched marked, as search results showed it. */
function Snippet({ text, match }: { text: string; match?: string }) {
    const at = match ? text.toLowerCase().indexOf(match.toLowerCase()) : -1;

    if (!match || at < 0) {
        return <>{text}</>;
    }

    return (
        <>
            {text.slice(0, at)}
            <mark className="rounded-[3px] bg-hl px-0.5 text-ink">{text.slice(at, at + match.length)}</mark>
            {text.slice(at + match.length)}
        </>
    );
}

/** Numbered so the person can say "abre el 2"; a click opens it without asking the model. */
function ItemList({ props }: Props<{ items: Item[]; empty?: string }>) {
    const trigger = useTriggerAction();
    const loading = useIsQueryLoading();
    const report = useContext(LiveItemsContext);
    const items = Array.isArray(props.items) ? props.items : [];
    const shown = JSON.stringify(items.map((item) => [item.n, item.title, item.ref]));

    // Items from a Query change while the view is open: say which are there now, for "abre el 2".
    useEffect(() => {
        report?.(
            items.flatMap((item) => {
                const opens = openRef(item.ref);

                return opens ? [{ n: item.n, title: item.title, ...opens }] : [];
            }),
        );
    }, [shown]); // eslint-disable-line react-hooks/exhaustive-deps

    if (items.length === 0) {
        return <p className="text-[13px] text-muted">{loading ? 'Cargando…' : (props.empty ?? 'No hay nada que enseñar.')}</p>;
    }

    const open = (item: Item) => void trigger(`Abre el ${item.n}`, undefined, { type: 'OpenItem', params: { n: item.n, ref: item.ref } });

    return (
        <ol className={`-mx-2.5 transition-opacity ${loading ? 'opacity-60' : ''}`}>
            {items.map((item) => (
                <li key={item.n} className="border-t border-line first:border-t-0">
                    <div
                        role="button"
                        tabIndex={0}
                        onClick={() => open(item)}
                        onKeyDown={(event) => event.key === 'Enter' && open(item)}
                        className="grid cursor-pointer grid-cols-[26px_minmax(0,1fr)_auto] items-start gap-3 rounded-[10px] px-2.5 py-2.5 hover:bg-soft"
                    >
                        <span
                            className={`mt-px grid size-[22px] place-items-center rounded-full border font-mono text-[11px] ${item.highlight ? 'border-accent bg-accent text-accent-ink' : 'border-line text-muted'}`}
                            aria-label={`Elemento ${item.n}`}
                        >
                            {item.n}
                        </span>
                        <span className="min-w-0">
                            {item.eyebrow && <span className="block truncate text-[12px] text-muted">{item.eyebrow}</span>}
                            <span className={`block truncate text-[14px] ${item.highlight ? 'font-semibold' : ''}`}>{item.title}</span>
                            {item.snippet && (
                                <span className="mt-0.5 line-clamp-2 block text-[13px] text-muted">
                                    <Snippet text={item.snippet} match={item.match} />
                                </span>
                            )}
                        </span>
                        <span className="flex flex-col items-end gap-1 text-right">
                            {item.status && <span className="rounded-full bg-soft px-2 py-0.5 text-[11.5px] whitespace-nowrap">{item.status}</span>}
                            {item.meta && <span className="text-[11.5px] whitespace-nowrap text-faint">{item.meta}</span>}
                        </span>
                    </div>
                </li>
            ))}
        </ol>
    );
}

function Chips({ props }: Props<{ items: string[] }>) {
    return (
        <ul aria-label="Filtros" className="-mt-1 flex flex-wrap gap-1.5">
            {props.items.map((item, index) => (
                <li key={index} className="rounded-full bg-soft px-2.5 py-0.5 text-[12px]">
                    {item}
                </li>
            ))}
        </ul>
    );
}

export const openUiLibrary = buildLibrary({ View, Row, Card, Tabs, Text, Metric, Table, BarChart, LineChart, DonutChart, Image, Document, List, Button, ItemList, Chips });
