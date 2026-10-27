/*
 * Nyuchi Travel Addons: service worker.
 *
 * Served by the plugin at /wta-sw.js (not a file in the web root). WTA_CONFIG
 * is replaced with the site's settings when the worker is served.
 *
 * Rules, in order of importance:
 *  1. A page is never answered from a cache while the network works. A
 *     navigation goes to the network; only a failed fetch (offline) is
 *     answered, with the precached offline page. HTML is never stored.
 *  2. Nothing private or dynamic is touched: wp-admin, login, REST, AJAX,
 *     every non-GET request, any URL carrying a nonce or preview, and the
 *     enquiry, checkout and cart pages are left to the browser.
 *  3. Only static files are cached: stale-while-revalidate for scripts,
 *     styles and the like under /wp-content/, cache-first (with limits) for
 *     fonts and images.
 */
const CONFIG = /*WTA_CONFIG*/ {};

const PREFIX = 'wta-pwa-';
const BUILD = PREFIX + CONFIG.build + '-';
const CACHE_PRECACHE = BUILD + 'precache';
const CACHE_ASSETS = BUILD + 'assets';
const CACHE_FONTS = BUILD + 'fonts';
const CACHE_IMAGES = BUILD + 'images';
const CURRENT = [CACHE_PRECACHE, CACHE_ASSETS, CACHE_FONTS, CACHE_IMAGES];
const STAMP = 'x-wta-cached-at';

const ASSET_EXT = /\.(?:css|js|mjs)$/i;
const FONT_EXT = /\.(?:woff2?|ttf|otf|eot)$/i;
const IMAGE_EXT = /\.(?:png|jpe?g|gif|webp|avif|svg|ico)$/i;

function isExcluded(url, request) {
  if (request.method !== 'GET') {
    return true;
  }
  if (request.headers.has('range')) {
    return true;
  }
  const path = url.pathname.toLowerCase();
  for (const part of CONFIG.exclude) {
    if (path.indexOf(part) !== -1) {
      return true;
    }
  }
  // Nonces and previews are per-visit and private; rest_route is the REST
  // API on a site without pretty permalinks.
  if (/nonce|preview|rest_route/i.test(url.search)) {
    return true;
  }
  return false;
}

function inScope(url) {
  return url.pathname.indexOf(CONFIG.scope) === 0;
}

function offlineFallback() {
  return caches
    .open(CACHE_PRECACHE)
    .then((cache) => cache.match(CONFIG.offlineUrl))
    .then((hit) => hit || inlineOffline());
}

function inlineOffline() {
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const html =
    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>' + esc(CONFIG.offlineTitle) + '</title>' +
    '<body style="font-family:system-ui,sans-serif;text-align:center;padding:3rem 1rem">' +
    '<h1>' + esc(CONFIG.offlineTitle) + '</h1><p>' + esc(CONFIG.offlineMessage) + '</p>';
  return new Response(html, { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      try {
        const cache = await caches.open(CACHE_PRECACHE);
        const urls = [CONFIG.offlineUrl].concat(CONFIG.precache || []);
        for (const u of urls) {
          try {
            await cache.add(new Request(u, { cache: 'reload', credentials: 'same-origin' }));
          } catch (e) {
            // A missing optional file must not stop the worker installing;
            // the inline offline page covers a missing offline page.
          }
        }
      } catch (e) {
        // Cache storage unavailable: carry on without it.
      }
      await self.skipWaiting();
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      try {
        const keys = await caches.keys();
        await Promise.all(
          keys
            .filter((k) => k.indexOf(PREFIX) === 0 && CURRENT.indexOf(k) === -1)
            .map((k) => caches.delete(k))
        );
      } catch (e) {
        // Old caches are cleaned on the next activation.
      }
      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  let url;
  try {
    url = new URL(request.url);
  } catch (e) {
    return;
  }

  const sameOrigin = url.origin === self.location.origin;

  if (sameOrigin && !inScope(url)) {
    return;
  }

  if (request.mode === 'navigate') {
    if (!sameOrigin || isExcluded(url, request)) {
      return;
    }
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (isExcluded(url, request)) {
    return;
  }

  const path = url.pathname;

  if (FONT_EXT.test(path)) {
    if (sameOrigin || CONFIG.fontHosts.indexOf(url.hostname) !== -1) {
      event.respondWith(cacheFirst(request, CACHE_FONTS, CONFIG.limits.fonts));
    }
    return;
  }

  if (!sameOrigin) {
    return;
  }

  if (path.indexOf('/wp-content/') === -1) {
    return;
  }

  if (IMAGE_EXT.test(path)) {
    event.respondWith(cacheFirst(request, CACHE_IMAGES, CONFIG.limits.images));
  } else if (ASSET_EXT.test(path)) {
    event.respondWith(staleWhileRevalidate(event, CACHE_ASSETS, CONFIG.limits.assets));
  }
});

async function networkFirstNavigation(request) {
  try {
    return await fetch(request);
  } catch (e) {
    // The network failed: this is the one case where we answer ourselves.
    try {
      return await offlineFallback();
    } catch (e2) {
      return inlineOffline();
    }
  }
}

async function staleWhileRevalidate(event, cacheName, limit) {
  const request = event.request;
  try {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);
    const refresh = fetch(request)
      .then((response) => store(cache, request, response, limit))
      .catch(() => null);
    // Keep the worker alive until the refresh has landed in the cache.
    event.waitUntil(refresh);
    if (hit) {
      return hit;
    }
    const response = await refresh;
    return response || (await fetch(request));
  } catch (e) {
    return fetch(request);
  }
}

async function cacheFirst(request, cacheName, limit) {
  try {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);
    if (hit && !expired(hit, limit)) {
      return hit;
    }
    try {
      const response = await fetch(request);
      const stored = await store(cache, request, response, limit);
      return stored || response;
    } catch (e) {
      if (hit) {
        return hit; // Expired but the network is down: better than nothing.
      }
      throw e;
    }
  } catch (e) {
    return fetch(request);
  }
}

function expired(response, limit) {
  const at = Number(response.headers.get(STAMP) || 0);
  return !at || Date.now() - at > limit.maxAgeSeconds * 1000;
}

// Cache a successful, reasonably small response; returns the response to use.
async function store(cache, request, response, limit) {
  if (!response || !response.ok || response.status !== 200 || response.type === 'opaque') {
    return response;
  }
  try {
    const blob = await response.clone().blob();
    if (blob.size > limit.maxBytes) {
      return response;
    }
    const headers = new Headers(response.headers);
    headers.set(STAMP, String(Date.now()));
    await cache.put(request, new Response(blob, { status: 200, statusText: response.statusText, headers }));
    await trim(cache, limit.maxEntries);
  } catch (e) {
    // Quota or clone failure: the page still gets the network response.
  }
  return response;
}

async function trim(cache, maxEntries) {
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - maxEntries; i++) {
    await cache.delete(keys[i]);
  }
}
