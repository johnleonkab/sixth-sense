import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'sixth-sense';

/**
 * After a rebuild or deploy, an open tab may ask for lazy chunks that no longer
 * exist. Reload once to pick up the new build instead of failing.
 */
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => undefined);

    navigator.serviceWorker.addEventListener('message', (event: MessageEvent<{ type?: string; url?: string }>) => {
        if (event.data?.type !== 'open-alert' || typeof event.data.url !== 'string') {
            return;
        }

        const target = new URL(event.data.url, window.location.origin);

        if (target.origin !== window.location.origin) {
            return;
        }

        router.visit(`${target.pathname}${target.search}${target.hash}`);
    });
}

window.addEventListener('vite:preloadError', () => {
    const key = 'reloaded-for-new-build-at';
    const lastReload = Number(sessionStorage.getItem(key) ?? 0);

    if (Date.now() - lastReload > 10_000) {
        sessionStorage.setItem(key, String(Date.now()));
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });

        return pages[`./pages/${name}.tsx`] as never;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: 'var(--accent)',
    },
});
