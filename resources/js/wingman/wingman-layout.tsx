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

            <div className="min-h-full overflow-x-clip px-4">
                <header className="mx-auto flex w-full min-w-0 max-w-[1120px] items-center justify-between gap-3 border-b border-line pt-[max(1rem,env(safe-area-inset-top))] pb-3.5 text-[13px] text-muted">
                    <div className="flex min-w-0 items-center gap-2">
                        <Wordmark />
                        <span aria-hidden className="text-faint">
                            /
                        </span>
                        <Link href="/wingman" className="truncate text-ink hover:underline">
                            Wingman
                        </Link>
                    </div>

                    <div className="flex shrink-0 items-center gap-1">
                        <AlertCenter embedInHeader />
                        <Link href={back.href} className="inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2.5 hover:bg-soft hover:text-ink md:min-h-0 md:py-1">
                            <span aria-hidden>←</span>
                            <span className="max-md:sr-only">{back.label}</span>
                            <span className="hidden md:inline">{back.label}</span>
                        </Link>
                    </div>
                </header>

                <main className="mx-auto w-full min-w-0 max-w-[880px] py-8 md:py-10">{children}</main>
            </div>

            <Toast />
        </>
    );
}
