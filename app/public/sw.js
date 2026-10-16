/* Service Worker der Kompanie-App (Scope /app/).
 *
 * - JS/CSS/Icons aus app-dist/ haben Hash-Dateinamen → cache-first.
 * - Seite /app/ und API-Antworten → network-first; ohne Netz die letzte Version.
 * Beim Abmelden löscht die App alle Caches.
 */
const STATIC_CACHE = 'mkl-static-v1';
const DATA_CACHE = 'mkl-data-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => ![STATIC_CACHE, DATA_CACHE].includes(k)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

function isStatic(url) {
  return url.pathname.includes('/mk-lank-app/app-dist/');
}

function isApi(url) {
  return url.pathname.includes('/wp-json/mk-lank/v1/') || (url.searchParams.get('rest_route') || '').startsWith('/mk-lank/v1/');
}

function cacheable(res) {
  return res && res.ok && !res.redirected && res.type === 'basic';
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const res = await fetch(request);
  if (cacheable(res)) {
    const cache = await caches.open(STATIC_CACHE);
    cache.put(request, res.clone());
  }
  return res;
}

async function networkFirst(request, fallbackUrl) {
  try {
    const res = await fetch(request);
    if (cacheable(res)) {
      const cache = await caches.open(DATA_CACHE);
      cache.put(fallbackUrl || request, res.clone());
    }
    return res;
  } catch (err) {
    const cached = await caches.match(fallbackUrl || request);
    if (cached) return cached;
    throw err;
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (isStatic(url)) {
    event.respondWith(cacheFirst(request));
  } else if (request.mode === 'navigate' && url.pathname.startsWith(new URL(self.registration.scope).pathname)) {
    event.respondWith(networkFirst(request, self.registration.scope));
  } else if (isApi(url)) {
    event.respondWith(networkFirst(request));
  }
});
