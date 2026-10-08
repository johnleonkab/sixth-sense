import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'sixth-sense';

/**
 * After a rebuild or deploy, an open tab may ask for lazy chunks that no longer
 * exist. Reload once to pick up the new build instead of failing.
 */
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
