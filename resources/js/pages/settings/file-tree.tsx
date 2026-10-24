import { Link } from '@inertiajs/react';
import { useEffect, useState, type Dispatch, type SetStateAction } from 'react';

type Branch = {
    name: string;
    path: string;
    file: boolean;
    children: Branch[];
};

const indent = ['pl-2', 'pl-5', 'pl-8', 'pl-11', 'pl-14', 'pl-16', 'pl-20', 'pl-24'];

/**
 * Nested markdown paths, folders first. The open document stays expanded.
 */
export default function FileTree({
    files,
    selected,
    dirty,
    repository,
}: {
    files: string[];
    selected: string | null;
    dirty: boolean;
    repository: string;
}) {
    const [open, setOpen] = useState<Set<string>>(() => ancestors(selected));

    useEffect(() => {
        setOpen((current) => {
            const next = new Set(current);

            for (const folder of ancestors(selected)) {
                next.add(folder);
            }

            return next;
        });
    }, [selected]);

    const nodes = tree(files);

    return (
        <nav aria-label="Árbol de archivos" className="flex max-h-56 shrink-0 flex-col overflow-auto border-b border-line md:max-h-none md:w-60 md:border-r md:border-b-0">
            <p className="sticky top-0 truncate bg-surface px-3 py-2 font-mono text-[11px] text-faint">{repository}</p>
            {nodes.length === 0 ? (
                <p className="px-3 py-2 text-[13px] text-muted">No hay documentos en main.</p>
            ) : (
                <ul className="px-1 pb-2">
                    {nodes.map((node) => (
                        <TreeNode key={node.path} node={node} depth={0} open={open} selected={selected} dirty={dirty} onToggle={toggle(setOpen)} />
                    ))}
                </ul>
            )}
        </nav>
    );
}

function TreeNode({
    node,
    depth,
    open,
    selected,
    dirty,
    onToggle,
}: {
    node: Branch;
    depth: number;
    open: Set<string>;
    selected: string | null;
    dirty: boolean;
    onToggle: (path: string) => void;
}) {
    const pad = indent[Math.min(depth, indent.length - 1)];

    if (node.file) {
        const current = node.path === selected;

        return (
            <li>
                <Link
                    href={`/settings/archive/files/${encodeURI(node.path)}`}
                    prefetch
                    aria-current={current ? 'page' : undefined}
                    title={node.path}
                    onClick={(event) => {
                        if (dirty && node.path !== selected && !window.confirm('Hay cambios sin guardar en main. ¿Abres otro documento?')) {
                            event.preventDefault();
                        }
                    }}
                    className={`flex min-h-8 items-center gap-1.5 truncate rounded-md py-1 text-[13px] hover:bg-soft ${pad} ${current ? 'bg-soft font-medium text-ink' : 'text-muted'}`}
                >
                    <FileIcon />
                    <span className="truncate">{node.name}</span>
                </Link>
            </li>
        );
    }

    const expanded = open.has(node.path);

    return (
        <li>
            <button
                type="button"
                aria-expanded={expanded}
                onClick={() => onToggle(node.path)}
                className={`flex min-h-8 w-full items-center gap-1.5 rounded-md py-1 text-left text-[13px] text-ink hover:bg-soft ${pad}`}
            >
                <Chevron open={expanded} />
                <span className="truncate font-medium">{node.name}</span>
            </button>
            {expanded && (
                <ul>
                    {node.children.map((child) => (
                        <TreeNode key={child.path} node={child} depth={depth + 1} open={open} selected={selected} dirty={dirty} onToggle={onToggle} />
                    ))}
                </ul>
            )}
        </li>
    );
}

function toggle(setOpen: Dispatch<SetStateAction<Set<string>>>) {
    return (path: string) => {
        setOpen((current) => {
            const next = new Set(current);

            if (next.has(path)) {
                next.delete(path);
            } else {
                next.add(path);
            }

            return next;
        });
    };
}

function ancestors(path: string | null): Set<string> {
    const folders = new Set<string>();

    if (!path) {
        return folders;
    }

    const parts = path.split('/');
    let accumulated = '';

    for (let index = 0; index < parts.length - 1; index += 1) {
        accumulated = accumulated === '' ? parts[index] : `${accumulated}/${parts[index]}`;
        folders.add(accumulated);
    }

    return folders;
}

function tree(paths: string[]): Branch[] {
    const root: Branch[] = [];
    const folders = new Map<string, Branch>();

    for (const filePath of paths) {
        const parts = filePath.split('/');
        let parent = root;
        let accumulated = '';

        for (let index = 0; index < parts.length; index += 1) {
            const name = parts[index];
            const path = accumulated === '' ? name : `${accumulated}/${name}`;
            const file = index === parts.length - 1;

            if (file) {
                parent.push({ name, path: filePath, file: true, children: [] });
                continue;
            }

            let folder = folders.get(path);

            if (!folder) {
                folder = { name, path, file: false, children: [] };
                folders.set(path, folder);
                parent.push(folder);
            }

            accumulated = path;
            parent = folder.children;
        }
    }

    const order = (nodes: Branch[]): Branch[] => {
        const next = [...nodes.filter((node) => !node.file), ...nodes.filter((node) => node.file)];

        for (const node of next) {
            node.children = order(node.children);
        }

        return next;
    };

    return order(root);
}

function Chevron({ open }: { open: boolean }) {
    return (
        <svg aria-hidden viewBox="0 0 12 12" className={`size-3 shrink-0 text-faint ${open ? 'rotate-90' : ''}`}>
            <path d="M4 2.5 8 6 4 9.5" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function FileIcon() {
    return (
        <svg aria-hidden viewBox="0 0 12 12" className="size-3 shrink-0 text-faint">
            <path d="M3 1.5h4l2 2V10.5H3z" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinejoin="round" />
            <path d="M7 1.5V4h2.5" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinejoin="round" />
        </svg>
    );
}
