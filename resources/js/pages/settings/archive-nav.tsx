import { Link, usePage } from '@inertiajs/react';

const sections = [
    { href: '/settings/archive/agents', label: 'Agentes' },
    { href: '/settings/archive/files', label: 'Archivos' },
];

/**
 * Subsections of the work archive. Agentes edits agent files.
 * Archivos reads and commits the markdown on main.
 */
export default function ArchiveNav() {
    const { url } = usePage();

    return (
        <nav aria-label="Secciones del archivo" className="mb-6 border-b border-line">
            <ul className="flex gap-1">
                {sections.map((section) => {
                    const current = url.startsWith(section.href);

                    return (
                        <li key={section.href}>
                            <Link
                                href={section.href}
                                aria-current={current ? 'page' : undefined}
                                className="inline-flex min-h-11 items-center border-b-2 border-transparent px-1 text-[13.5px] text-muted hover:text-ink aria-[current=page]:border-ink aria-[current=page]:font-medium aria-[current=page]:text-ink md:min-h-0 md:pb-2"
                            >
                                {section.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
