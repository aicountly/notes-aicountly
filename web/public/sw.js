/* ---------------------------------------------------------------------------
 * Service worker.
 *
 * Its job is the offline SHELL — the HTML, JS, CSS and icons needed to boot the
 * app with no network. The notes themselves are not cached here: they live in
 * IndexedDB, written by the app, where they can be searched, edited and queued
 * for sync. A cache entry cannot do any of that.
 *
 * What is deliberately NOT cached:
 *   - anything under /api/. Note bodies, search results and attachment content
 *     are private, and a Cache Storage entry survives sign-out and is readable
 *     by the next person to use this browser profile.
 *   - the auth callback, which must always reach the network.
 * ------------------------------------------------------------------------- */

const VERSION = 'notes-shell-v1'
const SHELL = ['/', '/index.html', '/manifest.webmanifest', '/apps/notes.png']

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(VERSION)
      // addAll rejects the whole install if one entry 404s; each is added
      // individually so a missing icon cannot stop the worker installing.
      .then((cache) => Promise.allSettled(SHELL.map((url) => cache.add(url)))),
    // Deliberately NOT skipWaiting(). The app watches for a waiting worker and
    // offers "Update available"; applyUpdate() then posts `skip-waiting` and
    // reloads. Skipping here made that promise undeliverable — the new worker
    // activated on its own, `registration.waiting` was empty so the prompt had
    // nothing to post to, and `activate` deletes the previous cache version,
    // which can strand a lazily-loaded chunk an open page has not fetched yet.
    // A reload under someone mid-sentence is exactly what OFFLINE_SYNC.md says
    // does not happen.
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  if (event.data === 'skip-waiting') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return

  const url = new URL(request.url)

  // Same-origin only. A cross-origin response is not ours to cache.
  if (url.origin !== self.location.origin) return

  // Never cache the API or the auth landing.
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/auth/')) return

  // Navigations: network first, so a deploy is picked up immediately, with the
  // cached shell as the offline answer.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          // Only a real shell is kept. Any navigation response used to be
          // stored under /index.html, so one 502 from the origin — a deploy
          // mid-flight, a proxy hiccup — replaced the offline shell with the
          // error page, and every later offline visit rendered that instead of
          // the app, until a successful navigation happened to overwrite it.
          // `basic` excludes opaque and redirected responses for the same
          // reason: neither is the document this app serves.
          if (response.ok && response.type === 'basic') {
            const copy = response.clone()
            void caches.open(VERSION).then((cache) => cache.put('/index.html', copy))
          }
          return response
        })
        .catch(() => caches.match('/index.html').then((cached) => cached ?? Response.error())),
    )
    return
  }

  // Hashed build assets never change under a given URL, so cache-first is both
  // safe and the reason a repeat visit paints instantly.
  if (/\.(?:js|css|woff2?|png|jpe?g|svg|webp|avif|ico)$/.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then(
        (cached) =>
          cached ??
          fetch(request).then((response) => {
            if (response.ok && response.type === 'basic') {
              const copy = response.clone()
              void caches.open(VERSION).then((cache) => cache.put(request, copy))
            }
            return response
          }),
      ),
    )
  }
})
