/**
 * ShibaLingo PWA Service Worker
 * Provides offline caching for static assets, styles, scripts, and pages
 */

const CACHE_NAME = 'shibalingo-v2.0.0';
const STATIC_ASSETS = [
    './assets/css/style.css',
    './assets/css/animations.css',
    './assets/js/app.js',
    './assets/js/vladikish-voice.js',
    './assets/js/mascot.js',
    './assets/js/lesson.js',
    './assets/js/markdown-renderer.js',
    './manifest.json',
    './translator.php',
    './conlang.php'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch((err) => {
                console.warn('Some assets could not be pre-cached during SW install:', err);
            });
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Skip POST/PUT or non-GET requests
    if (request.method !== 'GET') return;

    // Skip API or dynamic DB endpoints from aggressive caching
    if (url.pathname.includes('/api/') || url.pathname.includes('/admin/')) {
        return;
    }

    // Static assets (CSS, JS, Fonts, Images): Cache-First
    if (request.destination === 'style' || request.destination === 'script' || request.destination === 'image' || request.destination === 'font') {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }
                return fetch(request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
                    }
                    return networkResponse;
                }).catch(() => cachedResponse);
            })
        );
        return;
    }

    // HTML Navigation requests: Network-First with Cache Fallback
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => {
                return caches.match(request).then((cached) => {
                    return cached || caches.match('./conlang.php');
                });
            })
        );
    }
});
