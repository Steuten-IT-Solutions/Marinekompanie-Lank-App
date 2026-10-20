/* Service Worker der Kompanie-App (Scope /app/).
 *
 * - JS/CSS/Icons aus app-dist/ haben Hash-Dateinamen → cache-first.
 * - Seite /app/ und API-Antworten → network-first; ohne Netz die letzte Version.
 * - Dokumente (/app/dokument/<id>?v=<version>) → cache-first: Die Adresse ändert sich
 *   mit jeder neuen Fassung. Was einmal geöffnet wurde, ist so offline verfügbar und
 *   wird nicht bei jedem Öffnen neu geladen. Gespeichert wird nur die aktuelle Fassung.
 * Beim Abmelden löscht die App alle Caches. Im Wartungsmodus wird nichts überschrieben (siehe cacheable).
 *
 * Push: Der Server schickt verschlüsselt { title, body, url, tag, icon }. Ein Tipp auf
 * die Benachrichtigung öffnet die App an der passenden Stelle (z. B. #/meins/strafen).
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

function isDocument(url) {
  return url.pathname.startsWith(new URL(self.registration.scope).pathname + 'dokument/');
}

/**
 * Nur erfolgreiche Antworten speichern. Im Wartungsmodus (503, Code mkl_maintenance) bleibt so
 * alles Gespeicherte erhalten; auch die App-Seite mit X-MKL-Maintenance wird nicht gespeichert,
 * damit offline die letzte normale Fassung mit den gespeicherten Daten erscheint.
 */
function cacheable(res) {
  return res && res.ok && !res.redirected && res.type === 'basic' && !res.headers.get('X-MKL-Maintenance');
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

/** Cache-first; beim Speichern einer neuen Fassung werden ältere desselben Dokuments entfernt. */
async function documentFirst(request) {
  const cached = await caches.match(request.url);
  if (cached) return cached;
  const res = await fetch(request);
  if (cacheable(res)) {
    const cache = await caches.open(DATA_CACHE);
    const path = new URL(request.url).pathname;
    const old = (await cache.keys()).filter((k) => new URL(k.url).pathname === path && k.url !== request.url);
    await Promise.all(old.map((k) => cache.delete(k)));
    await cache.put(request.url, res.clone());
  }
  return res;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (isStatic(url)) {
    event.respondWith(cacheFirst(request));
  } else if (isDocument(url)) {
    event.respondWith(documentFirst(request));
  } else if (request.mode === 'navigate' && url.pathname.startsWith(new URL(self.registration.scope).pathname)) {
    event.respondWith(networkFirst(request, self.registration.scope));
  } else if (isApi(url)) {
    event.respondWith(networkFirst(request));
  }
});

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : '' };
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'Marinekompanie', {
      body: data.body || '',
      icon: data.icon,
      badge: data.icon,
      tag: data.tag || undefined,
      data: { url: data.url || self.registration.scope },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || self.registration.scope;
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      const client = windows.find((c) => c.url.startsWith(self.registration.scope));
      if (client) {
        await client.focus();
        // Offenes App-Fenster zur passenden Ansicht wechseln.
        if ('navigate' in client && client.url !== target) await client.navigate(target).catch(() => undefined);
        return;
      }
      await self.clients.openWindow(target);
    })(),
  );
});
