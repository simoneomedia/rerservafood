const CACHE_PREFIX = __CACHE_PREFIX__;

self.addEventListener('install', function(event) {
  self.skipWaiting();
});

self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys
          .filter(function(key) {
            return key.startsWith(CACHE_PREFIX);
          })
          .map(function(key) {
            return caches.delete(key);
          })
      );
    }).catch(function() {
      return Promise.resolve();
    }).then(function() {
      if (self.registration && self.registration.unregister) {
        return self.registration.unregister();
      }
      return true;
    }).then(function() {
      if (!self.clients || !self.clients.matchAll) {
        return true;
      }
      return self.clients.matchAll({ type: 'window' }).then(function(clients) {
        if (!clients || !clients.length) {
          return true;
        }
        var reloads = [];
        for (var i = 0; i < clients.length; i++) {
          var client = clients[i];
          if (client && typeof client.navigate === 'function') {
            reloads.push(client.navigate(client.url).catch(function() {}));
          }
        }
        if (reloads.length) {
          return Promise.all(reloads);
        }
        return true;
      });
    }).catch(function() {
      return Promise.resolve();
    })
  );
});
