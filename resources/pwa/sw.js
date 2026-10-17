const CACHE = 'sixth-sense-v1';

const OFFLINE = `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sin conexión · Sixth Sense</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0f1010; color: #ededeb; font: 16px/1.5 system-ui, sans-serif; }
  main { max-width: 28rem; padding: 1.5rem; text-align: center; }
  a { color: #8ea6ff; }
</style>
</head>
<body>
<main>
  <h1>Sin conexión</h1>
  <p>Sixth Sense necesita la red para tu jornada. Cuando vuelva, recarga.</p>
  <p><a href="/">Reintentar</a></p>
</main>
</body>
</html>`;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) =>
                cache.put(
                    new Request('/__offline'),
                    new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=UTF-8' } }),
                ),
            )
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET' || request.headers.has('range')) {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/__offline')));

        return;
    }

    const cacheable = url.pathname.startsWith('/build/') || url.pathname.startsWith('/brand/') || url.pathname.startsWith('/icon-') || url.pathname.endsWith('.png') || url.pathname.endsWith('.svg') || url.pathname.endsWith('.ico') || url.pathname.endsWith('.woff2');

    if (!cacheable) {
        return;
    }

    event.respondWith(
        caches.open(CACHE).then(async (cache) => {
            try {
                const response = await fetch(request);

                if (response.ok) {
                    await cache.put(request, response.clone());
                }

                return response;
            } catch {
                return (await cache.match(request)) ?? Response.error();
            }
        }),
    );
});
