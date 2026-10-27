// wta-pwa-kill-switch: replacement for the old WP Travel /sw.js, written by Nyuchi Travel Addons.
// Replacement for /sw.js: removes the old WP Travel service worker and its cache from returning visitors' browsers.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((k) => caches.delete(k))))
      .then(() => self.registration.unregister())
      .then(() => self.clients.matchAll({ type: 'window' }))
      .then((clients) => clients.forEach((c) => c.navigate(c.url)))
  );
});
