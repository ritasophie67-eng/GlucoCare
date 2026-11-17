// Service worker for the patient-facing experience.
// Caches the static shell only. Pages stay network-first, and nothing that changes
// data (POST) is ever cached — a stale reading list is better than a stale save.
const CACHE = 'glucocare-shell-v1';
const SHELL = [
  'assets/css/app.css',
  'assets/js/app.js',
  'assets/js/vendor/chart.umd.min.js',
  'assets/img/icon.svg',
  'offline.html',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;                        // never cache form posts
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('offline.html').then((hit) => hit || new Response('Offline', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      })))
    );
    return;
  }

  if (/\/assets\//.test(url.pathname) || url.pathname.endsWith('manifest.webmanifest')) {
    event.respondWith(
      caches.match(request).then((hit) => hit || fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
      }))
    );
  }
});
