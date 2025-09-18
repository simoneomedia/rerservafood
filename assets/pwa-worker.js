const CACHE_PREFIX = 'wcof-pwa-cache-';
const CACHE_VERSION = 'v1';
const CACHE_NAME = CACHE_PREFIX + CACHE_VERSION;

self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll([]);
    }).catch(function() {
      return Promise.resolve();
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys
          .filter(function(key) {
            return key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME;
          })
          .map(function(key) {
            return caches.delete(key);
          })
      );
    }).catch(function() {
      return Promise.resolve();
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(event) {
  var request = event.request;
  if (!request || request.method !== 'GET') {
    return;
  }

  var requestUrl;
  try {
    requestUrl = new URL(request.url);
  } catch (error) {
    return;
  }

  if (requestUrl.origin !== self.location.origin) {
    return;
  }

  event.respondWith(
    fetch(request)
      .then(function(response) {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }

        var responseToCache = response.clone();
        caches.open(CACHE_NAME).then(function(cache) {
          cache.put(request, responseToCache).catch(function() {});
        }).catch(function() {});

        return response;
      })
      .catch(function() {
        return caches.match(request).then(function(cacheResponse) {
          if (cacheResponse) {
            return cacheResponse;
          }
          return new Response('', {
            status: 504,
            statusText: 'Gateway Timeout'
          });
        });
      })
  );
});
