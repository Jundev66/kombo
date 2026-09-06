/*
 * The portal's service worker. Written by hand, and forty lines long.
 *
 * Workbox is ~20 KB of strategies of which two are used here, and those 20 KB
 * are paid for by somebody's metered phone.
 *
 * Two rules, and the second is the important one:
 *
 *   1. The shell (HTML, JS, CSS) is served from cache when present and updated
 *      behind the scenes. That is what makes the menu open instantly next time.
 *
 *   2. The API is NEVER cached. Not prices, not whether it is open, not an
 *      order's status. A menu cached from yesterday sells at yesterday's
 *      prices, and an "on the way" order that arrived half an hour ago is worse
 *      than saying nothing.
 */

const CACHE = 'kombo-portal-v1'
const SHELL = ['/', '/index.html']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)))
  // Without this, a new version waits for every tab to close — which on a
  // phone can be never.
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url)

  // Rule 2, first and without exceptions.
  if (url.pathname.startsWith('/api/') || event.request.method !== 'GET') {
    return
  }

  if (url.origin !== self.location.origin) {
    return
  }

  event.respondWith(
    caches.match(event.request).then((hit) => {
      const fresh = fetch(event.request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone()
            void caches.open(CACHE).then((cache) => cache.put(event.request, copy))
          }

          return response
        })
        // No network and no cache: let it fail the way it always fails, rather than
        // with an invented error that confuses further.
        .catch(() => hit)

      return hit ?? fresh
    }),
  )
})
