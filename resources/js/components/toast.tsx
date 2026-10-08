import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import type { Toast as ToastData } from '@/types';

const tone: Record<ToastData['type'], string> = {
    success: 'bg-ok',
    warning: 'bg-warn',
    error: 'bg-crit',
};

/**
 * One-off message flashed by the server after an action (connect, disconnect…).
 */
export default function Toast() {
    const { flash } = usePage();
    const [toast, setToast] = useState<ToastData | null>(null);

    useEffect(() => {
        if (!flash.toast) {
            return;
        }

        setToast(flash.toast);
        const timer = window.setTimeout(() => setToast(null), 6000);

        return () => window.clearTimeout(timer);
    }, [flash.toast]);

    if (!toast) {
        return null;
    }

    return (
        <div
            role={toast.type === 'error' ? 'alert' : 'status'}
            className="fixed inset-x-4 bottom-6 z-50 mx-auto flex max-w-[460px] items-start gap-3 rounded-xl border border-line bg-surface px-4 py-3 text-[13.5px] shadow-card"
        >
            <i aria-hidden className={`mt-[7px] size-2 flex-none rounded-full ${tone[toast.type]}`} />
            <p className="min-w-0 flex-1">{toast.message}</p>
            <button
                type="button"
                onClick={() => setToast(null)}
                aria-label="Cerrar aviso"
                className="-my-0.5 rounded-md px-1.5 text-muted hover:bg-soft hover:text-ink"
            >
                ×
            </button>
        </div>
    );
}
