(function () {
  'use strict';

  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return;
  }

  var settings = window.WCOF_PWA || {};
  var strings = settings.strings || {};
  var dismissKey = typeof settings.dismissKey === 'string' && settings.dismissKey ? settings.dismissKey : 'wcofPwaDismissed';
  var cooldownHours = parseInt(settings.cooldownHours, 10);
  if (!isFinite(cooldownHours) || cooldownHours <= 0) {
    cooldownHours = 168;
  }
  var cooldownMs = cooldownHours * 60 * 60 * 1000;
  var workerUrl = typeof settings.workerUrl === 'string' && settings.workerUrl ? settings.workerUrl : '/wcof-pwa-worker.js';
  var workerScope = typeof settings.workerScope === 'string' && settings.workerScope ? settings.workerScope : '/';

  var canUseStorage = false;
  try {
    var testKey = '__wcof_pwa_test__';
    window.localStorage.setItem(testKey, '1');
    window.localStorage.removeItem(testKey);
    canUseStorage = true;
  } catch (err) {
    canUseStorage = false;
  }

  var dismissedTimestamp = 0;
  if (canUseStorage) {
    var stored = window.localStorage.getItem(dismissKey);
    if (stored) {
      var parsed = parseInt(stored, 10);
      if (!isNaN(parsed)) {
        dismissedTimestamp = parsed;
      }
    }
  }

  var promptEvent = null;
  var banner = null;
  var installButton = null;
  var dismissButton = null;
  var messageElement = null;
  var isReady = false;
  var hasInitialized = false;
  var userAgent = (window.navigator && window.navigator.userAgent) ? window.navigator.userAgent.toLowerCase() : '';
  var isIos = /iphone|ipad|ipod/.test(userAgent);

  function isStandalone() {
    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
      return true;
    }
    if (window.navigator && window.navigator.standalone) {
      return true;
    }
    return false;
  }

  function rememberDismissal() {
    if (!canUseStorage) {
      return;
    }
    try {
      var now = Date.now();
      window.localStorage.setItem(dismissKey, String(now));
      dismissedTimestamp = now;
    } catch (err) {}
  }

  function hideBanner() {
    if (!banner) {
      return;
    }
    banner.setAttribute('hidden', 'hidden');
    banner.classList.remove('is-visible');
  }

  function shouldSkip() {
    if (isStandalone()) {
      return true;
    }
    if (canUseStorage && dismissedTimestamp) {
      if (Date.now() - dismissedTimestamp < cooldownMs) {
        return true;
      }
    }
    return false;
  }

  function showBanner() {
    if (!isReady || !banner) {
      return;
    }
    if (shouldSkip()) {
      return;
    }
    banner.classList.add('is-visible');
    banner.removeAttribute('hidden');
  }

  function handleBeforeInstallPrompt(event) {
    event.preventDefault();
    promptEvent = event;
    if (installButton && strings.installLabel) {
      installButton.textContent = strings.installLabel;
    }
    showBanner();
  }

  function showIosBanner() {
    if (!isIos) {
      return;
    }
    if (!isReady || !banner) {
      return;
    }
    if (shouldSkip()) {
      return;
    }
    if (messageElement && strings.iosMessage) {
      messageElement.textContent = strings.iosMessage;
    }
    if (installButton && strings.iosButton) {
      installButton.textContent = strings.iosButton;
    }
    showBanner();
  }

  function setup() {
    banner = document.getElementById('wcof-pwa-install');
    if (!banner) {
      return;
    }
    installButton = banner.querySelector('[data-wcof-pwa-install]');
    dismissButton = banner.querySelector('[data-wcof-pwa-dismiss]');
    messageElement = banner.querySelector('[data-wcof-pwa-message]');

    if (dismissButton) {
      dismissButton.addEventListener('click', function () {
        rememberDismissal();
        promptEvent = null;
        hideBanner();
      });
    }

    if (installButton) {
      installButton.addEventListener('click', function () {
        if (promptEvent) {
          var event = promptEvent;
          promptEvent = null;
          event.prompt();
          if (event.userChoice && event.userChoice.then) {
            event.userChoice.then(function (choice) {
              if (choice && choice.outcome === 'accepted') {
                rememberDismissal();
              }
            }).catch(function () {});
          }
          hideBanner();
        } else if (isIos) {
          rememberDismissal();
          hideBanner();
        } else {
          hideBanner();
        }
      });
    }

    isReady = true;

    if (promptEvent) {
      showBanner();
    } else if (isIos) {
      showIosBanner();
    }
  }

  function initializeAfterServiceWorker() {
    if (hasInitialized) {
      return;
    }
    hasInitialized = true;

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

    window.addEventListener('appinstalled', function () {
      rememberDismissal();
      hideBanner();
    });

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setup);
    } else {
      setup();
    }

    if (isIos) {
      window.setTimeout(function () {
        if (!promptEvent && isReady) {
          showIosBanner();
        }
      }, 1500);
    }
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      initializeAfterServiceWorker();
      return;
    }

    var hostname = window.location && window.location.hostname ? window.location.hostname : '';
    var isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
    if (window.isSecureContext === false && !isLocalhost) {
      initializeAfterServiceWorker();
      return;
    }

    var options = {};
    if (typeof workerScope === 'string' && workerScope) {
      options.scope = workerScope;
    }

    try {
      navigator.serviceWorker.register(workerUrl, options).then(function () {
        initializeAfterServiceWorker();
      }).catch(function (error) {
        console.error('[WCOF PWA] Failed to register service worker.', error);
        initializeAfterServiceWorker();
      });
    } catch (error) {
      console.error('[WCOF PWA] Service worker registration threw an error.', error);
      initializeAfterServiceWorker();
    }
  }

  registerServiceWorker();
})();
