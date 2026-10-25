import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

import AlertCenter from '@/alerts/alert-center';
import Toast from '@/components/toast';
import Wordmark from '@/components/wordmark';

type SettingsSection = {
    href: string;
    label: string;
    /** Prefix that marks the item current. Defaults to href. */
    match?: string;
};

const sections: SettingsSection[] = [
    { href: '/settings/connections', label: 'Conexiones' },
    { href: '/settings/archive/agents', match: '/settings/archive', label: 'Archivo' },
    { href: '/settings/alerts', label: 'Avisos' },
    { href: '/settings/context', label: 'Contexto' },
    { href: '/settings/wingman', label: 'Wingman' },
    { href: '/settings/system', label: 'Sistema' },
    { href: '/settings/impact', label: 'Impacto' },
];

type SettingsLayoutProps = {
    title: string;
    description?: string;
    /** Drop the reading column so a document and its editor can sit side by side. */
    wide?: boolean;
    /** Fill the screen under the header, for the archive file editor. */
    editor?: boolean;
    children: ReactNode;
};

function useNarrow(): boolean {
    const [narrow, setNarrow] = useState(() => window.matchMedia('(max-width: 767px)').matches);

    useEffect(() => {
        const query = window.matchMedia('(max-width: 767px)');
        const apply = () => setNarrow(query.matches);
        query.addEventListener('change', apply);

        return () => query.removeEventListener('change', apply);
    }, []);

    return narrow;
}

/**
 * Configuration area, deliberately separate from the assistant's daily
 * screen: its own header, navigation and a clear way back.
 */
export default function SettingsLayout({ title, description, wide = false, editor = false, children }: SettingsLayoutProps) {
    const { url } = usePage();
    const narrow = useNarrow();
    const frame = editor ? 'max-w-[1280px]' : 'max-w-[1120px]';
    const mainClass = editor ? 'flex min-h-0 flex-col' : wide ? 'min-w-0' : 'min-w-0 max-w-[760px]';

    return (
        <>
            <Head title={`${title} · Configuración`} />

            <div className="flex h-dvh max-w-full flex-col overflow-x-clip px-4">
                <header className={`chrome sticky top-0 z-20 mx-auto flex w-full min-w-0 ${frame} items-center justify-between gap-3 border-b border-line bg-bg pt-[max(1rem,env(safe-area-inset-top))] pb-3.5 text-[13px] text-muted`}>
                    <div className="hidden min-w-0 items-center gap-2 md:flex">
                        <Wordmark />
                        <span aria-hidden className="text-faint">
                            /
                        </span>
                        <span className="text-ink">Configuración</span>
                    </div>

                    <Link
                        href="/"
                        viewTransition={narrow}
                        onClick={() => {
                            document.documentElement.dataset.nav = 'back';
                        }}
                        className="inline-flex min-h-11 min-w-0 items-center gap-1.5 rounded-lg pr-2 text-[15px] font-medium text-ink md:hidden"
                    >
                        <span aria-hidden>←</span>
                        Asistente
                    </Link>

                    <div className="flex items-center gap-2">
                        <AlertCenter embedInHeader />
                        <Link href="/" className="hidden items-center gap-1.5 rounded-lg px-2.5 py-1 hover:bg-soft hover:text-ink md:inline-flex">
                            <span aria-hidden>←</span>
                            Volver al asistente
                        </Link>
                    </div>
                </header>

                <div
                    className={`mx-auto grid min-h-0 w-full min-w-0 ${frame} flex-1 overflow-x-clip md:grid-cols-[200px_minmax(0,1fr)] ${editor ? 'gap-4 overflow-hidden py-4 md:gap-6 md:py-5' : 'gap-6 overflow-y-auto py-5 md:gap-12 md:py-10'}`}
                >
                    <nav aria-label="Secciones de configuración" className="min-w-0">
                        <ul className="flex gap-1 overflow-x-auto overscroll-x-contain [-ms-overflow-style:none] [scrollbar-width:none] md:flex-col md:overflow-visible [&::-webkit-scrollbar]:hidden">
                            {sections.map((section) => {
                                const current = url.startsWith(section.match ?? section.href);

                                return (
                                    <li key={section.href} className="shrink-0">
                                        <Link
                                            href={section.href}
                                            aria-current={current ? 'page' : undefined}
                                            className="inline-flex min-h-11 items-center rounded-lg px-3 text-[13.5px] whitespace-nowrap text-muted hover:bg-soft hover:text-ink aria-[current=page]:bg-soft aria-[current=page]:font-medium aria-[current=page]:text-ink md:min-h-0 md:py-1.5"
                                        >
                                            {section.label}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>

                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="mt-3 flex min-h-11 w-full items-center justify-center rounded-xl border border-line text-[13.5px] text-muted hover:bg-soft hover:text-ink md:hidden"
                        >
                            Cerrar sesión
                        </Link>
                    </nav>

                    <main className={mainClass}>
                        <div className={editor ? 'mb-3 shrink-0' : 'mb-8'}>
                            <h1 className={editor ? 'text-[15px] font-medium' : 'text-[22px] font-medium tracking-[-0.02em]'}>{title}</h1>
                            {description && (
                                <p className={editor ? 'mt-0.5 truncate text-[12.5px] text-muted' : 'mt-1.5 max-w-[560px] text-muted'}>{description}</p>
                            )}
                        </div>

                        <div className={editor ? 'flex min-h-0 flex-1 flex-col' : undefined}>{children}</div>
                    </main>
                </div>
            </div>

            <Toast />
        </>
    );
}
