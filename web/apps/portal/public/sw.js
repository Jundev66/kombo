/*
 * El service worker del portal. Escrito a mano, y son cuarenta líneas.
 *
 * Workbox son ~20 KB de estrategias de las que aquí se usan dos, y esos 20 KB
 * los paga el teléfono de alguien con datos contados. Lo que hace falta es
 * corto y cabe en un fichero que se puede leer entero.
 *
 * **Dos reglas, y la segunda es la importante:**
 *
 *   1. El armazón (HTML, JS, CSS) se sirve de la caché si está, y se actualiza
 *      por detrás. Es lo que hace que la carta abra al instante la segunda vez.
 *
 *   2. **La API NUNCA se cachea.** Ni los precios, ni si está abierto, ni el
 *      estado de un pedido. Una carta guardada de ayer vende a precios de ayer,
 *      y un pedido «en camino» que en realidad ya llegó hace media hora es peor
 *      que no decir nada.
 */

const CACHE = 'kombo-portal-v1'
const SHELL = ['/', '/index.html']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)))
  // Sin esto, una versión nueva se queda esperando a que se cierren todas las
  // pestañas — que en un teléfono puede ser nunca.
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

  // Regla 2, primero y sin excepciones.
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
        // Sin red y sin caché: que falle como falla siempre, no con un error
        // inventado que confunda más.
        .catch(() => hit)

      return hit ?? fresh
    }),
  )
})
