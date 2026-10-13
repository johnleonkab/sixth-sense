import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AlertCenter from '@/alerts/alert-center';
import Toast from '@/components/toast';
import Wordmark from '@/components/wordmark';

type SettingsSection = {
    href: string;
    label: string;
};

const sections: SettingsSection[] = [
    { href: '/settings/connections', label: 'Conexiones' },
    { href: '/settings/alerts', label: 'Avisos' },
    { href: '/settings/context', label: 'Contexto' },
    { href: '/settings/wingman', label: 'Wingman' },
    { href: '/settings/system', label: 'Sistema' },
];

type SettingsLayoutProps = {
    title: string;
    description?: string;
    children: ReactNode;
};

/**
 * Configuration area, deliberately separate from the assistant's daily
 * screen: its own header, navigation and a clear way back.
 */
export default function SettingsLayout({ title, description, children }: SettingsLayoutProps) {
    const { url } = usePage();

    return (
        <>
            <Head title={`${title} · Configuración`} />

            <div className="min-h-full px-4">
                <header className="mx-auto flex w-full max-w-[1120px] items-center justify-between gap-3 border-b border-line pt-4 pb-3.5 text-[13px] text-muted">
                    <div className="flex min-w-0 items-center gap-2">
                        <Wordmark />
                        <span aria-hidden className="text-faint">
                            /
                        </span>
                        <span className="text-ink">Configuración</span>
                    </div>

                    <Link
                        href="/"
                        className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 hover:bg-soft hover:text-ink"
                    >
                        <span aria-hidden>←</span>
                        Volver al asistente
                    </Link>
                </header>

                <div className="mx-auto grid w-full max-w-[1120px] gap-8 py-8 md:grid-cols-[200px_minmax(0,1fr)] md:gap-12 md:py-10">
                    <nav aria-label="Secciones de configuración">
                        <ul className="flex gap-1 overflow-x-auto md:flex-col">
                            {sections.map((section) => {
                                const current = url.startsWith(section.href);

                                return (
                                    <li key={section.href}>
                                        <Link
                                            href={section.href}
                                            aria-current={current ? 'page' : undefined}
                                            className="block rounded-lg px-3 py-1.5 text-[13.5px] whitespace-nowrap text-muted hover:bg-soft hover:text-ink aria-[current=page]:bg-soft aria-[current=page]:font-medium aria-[current=page]:text-ink"
                                        >
                                            {section.label}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>

                    <main className="min-w-0 max-w-[760px]">
                        <div className="mb-8">
                            <h1 className="text-[22px] font-medium tracking-[-0.02em]">{title}</h1>
                            {description && <p className="mt-1.5 max-w-[560px] text-muted">{description}</p>}
                        </div>

                        {children}
                    </main>
                </div>
            </div>

            <Toast />
            <AlertCenter />
        </>
    );
}
