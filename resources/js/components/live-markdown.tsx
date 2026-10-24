import { useEffect, useLayoutEffect, useRef, useState, type ClipboardEvent, type FormEvent, type KeyboardEvent } from 'react';

type Kind = 'text' | 'mark' | 'label' | 'bold' | 'em' | 'code' | 'strike' | 'link';

type Segment = {
    text: string;
    kind: Kind;
};

type LineStyle = 'p' | 'h1' | 'h2' | 'h3' | 'h4' | 'quote' | 'ul' | 'ol' | 'task' | 'code' | 'fence' | 'hr';

type LineModel = {
    style: LineStyle;
    segments: Segment[];
};

type Snapshot = {
    value: string;
    caret: number;
};

const headingClass: Record<'h1' | 'h2' | 'h3' | 'h4', string> = {
    h1: 'text-[1.7em] font-medium leading-snug tracking-[-0.02em]',
    h2: 'text-[1.4em] font-medium leading-snug',
    h3: 'text-[1.18em] font-medium leading-snug',
    h4: 'font-medium leading-snug',
};

/**
 * One markdown surface. Symbols stay in the text, and their effect shows in
 * place: a heading grows, **this** is bold. Marks hide on the lines the cursor
 * has left, the way Obsidian's live preview does.
 */
export default function LiveMarkdown({
    value,
    onChange,
    readOnly = false,
    resetKey = '',
    label,
    onSave,
}: {
    value: string;
    onChange: (value: string) => void;
    readOnly?: boolean;
    resetKey?: string;
    label: string;
    onSave?: () => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const valueRef = useRef(value);
    const caretRef = useRef(0);
    const restoreRef = useRef(false);
    const composingRef = useRef(false);
    const historyRef = useRef<Snapshot[]>([{ value, caret: 0 }]);
    const historyAt = useRef(0);
    const activeRef = useRef(-1);
    const [activeLine, setActiveLine] = useState(-1);

    valueRef.current = value;

    useEffect(() => {
        historyRef.current = [{ value, caret: 0 }];
        historyAt.current = 0;
        activeRef.current = -1;
        setActiveLine(-1);
    }, [resetKey]);

    useLayoutEffect(() => {
        if (!restoreRef.current || !ref.current) {
            return;
        }

        restoreRef.current = false;

        if (document.activeElement === ref.current) {
            placeCaret(ref.current, caretRef.current);
        }
    });

    const commit = (next: string, caret: number, record: boolean) => {
        if (record) {
            const history = historyRef.current.slice(0, historyAt.current + 1);
            const last = history[history.length - 1];

            if (!last || last.value !== next) {
                history.push({ value: next, caret });

                if (history.length > 100) {
                    history.shift();
                }

                historyRef.current = history;
                historyAt.current = history.length - 1;
            }
        }

        caretRef.current = caret;
        restoreRef.current = true;
        const line = lineAt(next, caret);
        activeRef.current = line;
        setActiveLine(line);
        onChange(next);
    };

    const syncFromDom = () => {
        if (!ref.current || composingRef.current) {
            return;
        }

        const next = readSource(ref.current);
        const caret = readCaret(ref.current);

        if (next === valueRef.current) {
            caretRef.current = caret;
            const line = lineAt(next, caret);
            activeRef.current = line;
            setActiveLine(line);

            return;
        }

        commit(next, caret, true);
    };

    const replace = (start: number, end: number, insert: string) => {
        const current = valueRef.current;
        const from = Math.max(0, Math.min(start, end, current.length));
        const to = Math.max(from, Math.min(Math.max(start, end), current.length));

        commit(current.slice(0, from) + insert + current.slice(to), from + insert.length, true);
    };

    const onBeforeInput = (event: FormEvent<HTMLDivElement>) => {
        const input = event.nativeEvent;

        if (!(input instanceof InputEvent) || readOnly || composingRef.current || !ref.current) {
            return;
        }

        if (input.inputType === 'insertCompositionText' || input.inputType === 'historyUndo' || input.inputType === 'historyRedo') {
            if (input.inputType !== 'insertCompositionText') {
                event.preventDefault();
            }

            return;
        }

        const { start, end } = caretRange(ref.current);
        const current = valueRef.current;

        if (input.inputType === 'insertText' && input.data) {
            event.preventDefault();
            replace(start, end, input.data);

            return;
        }

        if (input.inputType === 'insertParagraph' || input.inputType === 'insertLineBreak') {
            event.preventDefault();
            replace(start, end, '\n');

            return;
        }

        if (input.inputType === 'insertFromPaste') {
            event.preventDefault();

            return;
        }

        if (input.inputType === 'deleteContentBackward' || input.inputType === 'deleteWordBackward') {
            event.preventDefault();

            if (start !== end) {
                replace(start, end, '');
            } else if (start > 0) {
                const from = input.inputType === 'deleteWordBackward' ? wordBoundary(current, start) : start - 1;
                replace(from, start, '');
            }

            return;
        }

        if (input.inputType === 'deleteContentForward' || input.inputType === 'deleteWordForward') {
            event.preventDefault();

            if (start !== end) {
                replace(start, end, '');
            } else if (start < current.length) {
                const to = input.inputType === 'deleteWordForward' ? wordBoundary(current, start, true) : start + 1;
                replace(start, to, '');
            }
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (readOnly) {
            return;
        }

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            onSave?.();

            return;
        }

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            const next = event.shiftKey ? historyAt.current + 1 : historyAt.current - 1;
            const snap = historyRef.current[next];

            if (!snap) {
                return;
            }

            historyAt.current = next;
            caretRef.current = snap.caret;
            restoreRef.current = true;
            const line = lineAt(snap.value, snap.caret);
            activeRef.current = line;
            setActiveLine(line);
            onChange(snap.value);

            return;
        }

        if (event.key === 'Tab') {
            event.preventDefault();

            if (ref.current) {
                const { start, end } = caretRange(ref.current);
                replace(start, end, '  ');
            }
        }
    };

    const onPaste = (event: ClipboardEvent<HTMLDivElement>) => {
        if (readOnly || !ref.current) {
            return;
        }

        event.preventDefault();
        const { start, end } = caretRange(ref.current);
        replace(start, end, event.clipboardData.getData('text/plain'));
    };

    const remember = () => {
        if (!ref.current || composingRef.current) {
            return;
        }

        const caret = readCaret(ref.current);
        const line = lineAt(valueRef.current, caret);
        caretRef.current = caret;

        if (line === activeRef.current) {
            return;
        }

        activeRef.current = line;
        restoreRef.current = true;
        setActiveLine(line);
    };

    const lines = value.split('\n');
    let inFence = false;

    return (
        <div className="relative min-h-0 flex-1">
            {value === '' && <p className="pointer-events-none absolute top-5 left-8 text-faint">Escribe # o ** para dar formato…</p>}
            <div
                ref={ref}
                role="textbox"
                aria-multiline="true"
                aria-label={label}
                aria-readonly={readOnly}
                contentEditable={!readOnly}
                suppressContentEditableWarning
                spellCheck={false}
                onBeforeInput={onBeforeInput}
                onInput={syncFromDom}
                onKeyDown={onKeyDown}
                onPaste={onPaste}
                onCompositionStart={() => {
                    composingRef.current = true;
                }}
                onCompositionEnd={() => {
                    composingRef.current = false;
                    syncFromDom();
                }}
                onMouseUp={remember}
                onKeyUp={remember}
                className="h-full min-h-64 overflow-auto px-8 py-5 text-[16.5px] leading-8 text-ink outline-none"
            >
                {lines.map((line, index) => {
                    const model = modelLine(line, inFence);
                    inFence = model.inFence;
                    const active = index === activeLine;

                    return (
                        <div key={index} data-line className={lineClass(model.style, active)}>
                            {model.segments.map((segment, segmentIndex) => (
                                <span key={segmentIndex} className={segmentClass(segment.kind, active)}>
                                    {segment.text}
                                </span>
                            ))}
                            {line === '' && <br />}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function modelLine(line: string, inFence: boolean): LineModel & { inFence: boolean } {
    if (line.startsWith('```')) {
        return { style: 'fence', segments: [{ text: line, kind: 'text' }], inFence: !inFence };
    }

    if (inFence) {
        return { style: 'code', segments: line === '' ? [] : [{ text: line, kind: 'text' }], inFence: true };
    }

    if (/^(-{3,}|\*{3,})$/.test(line)) {
        return { style: 'hr', segments: [{ text: line, kind: 'mark' }], inFence: false };
    }

    const heading = /^(#{1,6})([ \t]+)(.*)$/.exec(line);

    if (heading) {
        const level = heading[1].length;
        const style: LineStyle = level === 1 ? 'h1' : level === 2 ? 'h2' : level === 3 ? 'h3' : 'h4';

        return {
            style,
            segments: [
                { text: heading[1], kind: 'mark' },
                { text: heading[2], kind: 'mark' },
                ...inline(heading[3]),
            ],
            inFence: false,
        };
    }

    const task = /^(\s*)(- \[[ xX]\])(\s+)(.*)$/.exec(line);

    if (task) {
        return {
            style: 'task',
            segments: [
                { text: task[1], kind: 'text' },
                { text: task[2], kind: 'mark' },
                { text: task[3], kind: 'mark' },
                ...inline(task[4]),
            ],
            inFence: false,
        };
    }

    const list = /^(\s*)([-*]|\d+\.)(\s+)(.*)$/.exec(line);

    if (list) {
        const ordered = /\d/.test(list[2]);

        return {
            style: ordered ? 'ol' : 'ul',
            segments: [
                { text: list[1], kind: 'text' },
                { text: list[2], kind: ordered ? 'label' : 'mark' },
                { text: list[3], kind: ordered ? 'label' : 'mark' },
                ...inline(list[4]),
            ],
            inFence: false,
        };
    }

    const quote = /^(\s*)(>)(\s?)(.*)$/.exec(line);

    if (quote) {
        return {
            style: 'quote',
            segments: [
                { text: quote[1], kind: 'text' },
                { text: quote[2], kind: 'mark' },
                { text: quote[3], kind: 'mark' },
                ...inline(quote[4]),
            ],
            inFence: false,
        };
    }

    return { style: 'p', segments: inline(line), inFence: false };
}

function inline(input: string): Segment[] {
    const segments: Segment[] = [];
    let cursor = 0;

    const push = (kind: Kind, text: string) => {
        if (text !== '') {
            segments.push({ text, kind });
        }
    };

    while (cursor < input.length) {
        const rest = input.slice(cursor);

        if (rest.startsWith('**')) {
            const close = rest.indexOf('**', 2);

            if (close > 2) {
                push('mark', '**');
                push('bold', rest.slice(2, close));
                push('mark', '**');
                cursor += close + 2;
                continue;
            }
        }

        if (rest.startsWith('~~')) {
            const close = rest.indexOf('~~', 2);

            if (close > 2) {
                push('mark', '~~');
                push('strike', rest.slice(2, close));
                push('mark', '~~');
                cursor += close + 2;
                continue;
            }
        }

        if (rest.startsWith('*') && !rest.startsWith('**')) {
            const close = rest.indexOf('*', 1);

            if (close > 1 && rest[close - 1] !== '*') {
                push('mark', '*');
                push('em', rest.slice(1, close));
                push('mark', '*');
                cursor += close + 1;
                continue;
            }
        }

        if (rest.startsWith('`')) {
            const close = rest.indexOf('`', 1);

            if (close > 1) {
                push('mark', '`');
                push('code', rest.slice(1, close));
                push('mark', '`');
                cursor += close + 1;
                continue;
            }
        }

        const link = /^\[([^\]]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/.exec(rest);

        if (link) {
            push('mark', '[');
            push('link', link[1]);
            push('mark', `](${link[2]})`);
            cursor += link[0].length;
            continue;
        }

        const next = rest.slice(1).search(/[*`~[]/);
        const take = next === -1 ? rest.length : next + 1;
        push('text', rest.slice(0, take));
        cursor += take;
    }

    return segments;
}

function lineClass(style: LineStyle, active: boolean): string {
    const shared = 'min-h-[1.6em] whitespace-pre-wrap break-words';

    if (style === 'h1' || style === 'h2' || style === 'h3' || style === 'h4') {
        return `${shared} ${headingClass[style]}`;
    }

    if (style === 'quote') {
        return `${shared} border-l-2 border-line pl-3 text-muted`;
    }

    if (style === 'ul' && !active) {
        return `${shared} before:mr-2 before:text-muted before:content-['•']`;
    }

    if (style === 'task' && !active) {
        return `${shared} before:mr-2 before:content-['☐']`;
    }

    if (style === 'code' || style === 'fence') {
        return `${shared} font-mono text-[13.5px] leading-7 ${style === 'fence' ? 'text-faint' : ''}`;
    }

    if (style === 'hr') {
        return active ? `${shared} text-faint` : 'my-3 min-h-4 border-b border-line';
    }

    return shared;
}

function segmentClass(kind: Kind, active: boolean): string {
    if (kind === 'mark') {
        return active ? 'text-[0.72em] font-normal text-faint' : 'inline-block w-0 overflow-hidden whitespace-pre';
    }

    if (kind === 'label') {
        return 'text-muted';
    }

    if (kind === 'bold') {
        return 'font-semibold';
    }

    if (kind === 'em') {
        return 'italic';
    }

    if (kind === 'code') {
        return 'rounded bg-soft px-1 font-mono text-[0.9em]';
    }

    if (kind === 'strike') {
        return 'line-through';
    }

    if (kind === 'link') {
        return 'text-accent underline underline-offset-2';
    }

    return '';
}

function lineAt(value: string, offset: number): number {
    let line = 0;

    for (let index = 0; index < offset && index < value.length; index += 1) {
        if (value[index] === '\n') {
            line += 1;
        }
    }

    return line;
}

function wordBoundary(value: string, offset: number, forward = false): number {
    let index = offset;

    if (forward) {
        while (index < value.length && /\s/.test(value[index])) {
            index += 1;
        }

        while (index < value.length && !/\s/.test(value[index])) {
            index += 1;
        }

        return index;
    }

    while (index > 0 && /\s/.test(value[index - 1])) {
        index -= 1;
    }

    while (index > 0 && !/\s/.test(value[index - 1])) {
        index -= 1;
    }

    return index;
}

function readSource(root: HTMLElement): string {
    return [...root.querySelectorAll<HTMLElement>('[data-line]')]
        .map((line) => line.textContent?.replace(/\n/g, '') ?? '')
        .join('\n');
}

function readCaret(root: HTMLElement): number {
    const range = caretRange(root);

    return range.start;
}

function caretRange(root: HTMLElement): { start: number; end: number } {
    const selection = window.getSelection();

    if (!selection || selection.rangeCount === 0 || !selection.anchorNode) {
        return { start: 0, end: 0 };
    }

    const start = point(root, selection.anchorNode, selection.anchorOffset);
    const end = point(root, selection.focusNode, selection.focusOffset);

    return { start: Math.min(start, end), end: Math.max(start, end) };
}

function point(root: HTMLElement, node: Node | null, nodeOffset: number): number {
    if (!node) {
        return 0;
    }

    const lines = [...root.querySelectorAll<HTMLElement>('[data-line]')];
    let passed = 0;

    for (const line of lines) {
        if (line.contains(node)) {
            return passed + offsetIn(line, node, nodeOffset);
        }

        passed += (line.textContent?.replace(/\n/g, '').length ?? 0) + 1;
    }

    return passed;
}

function offsetIn(line: HTMLElement, node: Node, nodeOffset: number): number {
    if (node === line) {
        return [...line.childNodes].slice(0, nodeOffset).reduce((count, child) => count + (child.textContent?.length ?? 0), 0);
    }

    const walker = document.createTreeWalker(line, NodeFilter.SHOW_TEXT);
    let count = 0;
    let current = walker.nextNode();

    while (current) {
        if (current === node) {
            return count + nodeOffset;
        }

        count += current.textContent?.length ?? 0;
        current = walker.nextNode();
    }

    return count;
}

function placeCaret(root: HTMLElement, offset: number): void {
    const lines = [...root.querySelectorAll<HTMLElement>('[data-line]')];
    let remaining = offset;

    for (let index = 0; index < lines.length; index += 1) {
        const length = lines[index].textContent?.replace(/\n/g, '').length ?? 0;

        if (remaining <= length || index === lines.length - 1) {
            placeIn(lines[index], Math.max(0, Math.min(remaining, length)));

            return;
        }

        remaining -= length + 1;
    }
}

function placeIn(line: HTMLElement, offset: number): void {
    const walker = document.createTreeWalker(line, NodeFilter.SHOW_TEXT);
    let left = offset;
    let node = walker.nextNode() as Text | null;

    while (node) {
        if (left <= node.length) {
            const range = document.createRange();
            range.setStart(node, left);
            range.collapse(true);
            const selection = window.getSelection();
            selection?.removeAllRanges();
            selection?.addRange(range);

            return;
        }

        left -= node.length;
        node = walker.nextNode() as Text | null;
    }

    const range = document.createRange();
    range.selectNodeContents(line);
    range.collapse(false);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
}
