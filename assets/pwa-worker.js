const CACHE_PREFIX = __CACHE_PREFIX__;
const CACHE_VERSION = __CACHE_VERSION__;
const CACHE_NAME = CACHE_PREFIX + CACHE_VERSION;
const START_URL = __START_URL__;
const OFFLINE_HTML = __OFFLINE_HTML__;
const OFFLINE_RESPONSE = new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });

self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll([START_URL]).catch(function() {
        return Promise.resolve();
      });
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
    }).then(function() {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function(event) {
  var request = event.request;
  if (!request || request.method !== 'GET') {
    return;
  }

  var url;
  try {
    url = new URL(request.url);
  } catch (error) {
    return;
  }

  if (url.origin !== self.location.origin) {
    return;
  }

  var accept = request.headers && request.headers.get ? request.headers.get('accept') || '' : '';
  var isNavigation = request.mode === 'navigate' || accept.indexOf('text/html') !== -1;

  if (!isNavigation) {
    return;
  }

  event.respondWith(
    fetch(request)
      .then(function(response) {
        if (response && response.status === 200 && (response.type === 'basic' || response.type === 'default')) {
          var cacheCopy = response.clone();
          var startCopy = request.mode === 'navigate' ? response.clone() : null;

          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(request, cacheCopy).catch(function() {});
            if (startCopy) {
              cache.put(START_URL, startCopy).catch(function() {});
            }
          }).catch(function() {});
        }
        return response;
      })
      .catch(function() {
        return caches.match(request).then(function(match) {
          if (match) {
            return match;
          }
          return caches.match(START_URL).then(function(fallback) {
            if (fallback) {
              return fallback;
            }
            return OFFLINE_RESPONSE.clone();
          });
        });
      })
  );
});
