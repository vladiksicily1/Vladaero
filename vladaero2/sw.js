const CACHE_NAME = 'vladaero-v1';
const STATIC_ASSETS = [
    '/',
    '/public/css/main.css',
    '/public/css/components.css',
    '/public/css/aviation.css',
    '/public/js/app.js',
    '/public/js/ai-widget.js',
    '/manifest.json',
];

// Install — cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate — cleanup old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// Fetch — network-first for pages, cache-first for static
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET and API calls
    if (request.method !== 'GET' || url.pathname.startsWith('/api/') || url.pathname.startsWith('/telegram/')) {
        return;
    }

    // Static assets — cache first
    if (url.pathname.match(/\.(css|js|png|jpg|jpeg|webp|svg|woff2|ico)$/)) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(response => {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                    return response;
                });
            })
        );
        return;
    }

    // Pages — network first, fallback to cache
    event.respondWith(
        fetch(request)
            .then(response => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(request, clone));
                return response;
            })
            .catch(() => caches.match(request))
    );
});
