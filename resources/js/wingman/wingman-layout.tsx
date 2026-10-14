import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AlertCenter from '@/alerts/alert-center';
import Toast from '@/components/toast';
import Wordmark from '@/components/wordmark';

type WingmanLayoutProps = {
    title: string;
    /** Where "back" leads: the meetings list from a meeting, the assistant from the list. */
    back?: { href: string; label: string };
    children: ReactNode;
};

/**
 * Wingman's pages: off the assistant's daily screen, a step away when needed.
 */
export default function WingmanLayout({ title, back = { href: '/', label: 'Volver al asistente' }, children }: WingmanLayoutProps) {
    return (
        <>
            <Head title={`${title} · Wingman`} />

            <div className="min-h-full px-4">
                <header className="mx-auto flex w-full max-w-[1120px] items-center justify-between gap-3 border-b border-line pt-4 pb-3.5 text-[13px] text-muted">
                    <div className="flex min-w-0 items-center gap-2">
                        <Wordmark />
                        <span aria-hidden className="text-faint">
                            /
                        </span>
                        <Link href="/wingman" className="text-ink hover:underline">
                            Wingman
                        </Link>
                    </div>

                    <Link href={back.href} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 hover:bg-soft hover:text-ink">
                        <span aria-hidden>←</span>
                        {back.label}
                    </Link>
                </header>

                <main className="mx-auto w-full max-w-[880px] py-8 md:py-10">{children}</main>
            </div>

            <Toast />
            <AlertCenter />
        </>
    );
}
