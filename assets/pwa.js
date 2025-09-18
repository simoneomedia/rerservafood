(function () {
  'use strict';

  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return;
  }

  var settings = window.WCOF_PWA || {};
  var strings = settings.strings || {};
  var swUrl = typeof settings.swUrl === 'string' ? settings.swUrl : '';
  if (swUrl) {
    try {
      var parsedSwUrl = new URL(swUrl, window.location.href);
      if (parsedSwUrl.origin === window.location.origin) {
        swUrl = parsedSwUrl.href;
      } else {
        swUrl = '';
      }
    } catch (err) {
      swUrl = '';
    }
  }
  var swRegistrationAttempted = false;

  function registerServiceWorker() {
    if (swRegistrationAttempted) {
      return;
    }
    swRegistrationAttempted = true;

    if (!swUrl) {
      return;
    }
    if (!window.navigator || !('serviceWorker' in window.navigator)) {
      return;
    }
    try {
      var registerPromise = window.navigator.serviceWorker.register(swUrl);
      if (registerPromise && typeof registerPromise.then === 'function') {
        registerPromise.then(function (registration) {
          if (registration && typeof registration.update === 'function') {
            try {
              registration.update();
            } catch (err) {}
          }
        }).catch(function () {});
      }
    } catch (err) {}
  }

  if (swUrl) {
    if (document.readyState === 'complete') {
      registerServiceWorker();
    } else {
      registerServiceWorker();
      window.addEventListener('load', registerServiceWorker);
    }
  }

  var dismissKey = typeof settings.dismissKey === 'string' && settings.dismissKey ? settings.dismissKey : 'wcofPwaDismissed';
  var cooldownHours = parseInt(settings.cooldownHours, 10);
  if (!isFinite(cooldownHours) || cooldownHours <= 0) {
    cooldownHours = 168;
  }
  var cooldownMs = cooldownHours * 60 * 60 * 1000;

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

  window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

  window.addEventListener('appinstalled', function () {
    rememberDismissal();
    hideBanner();
  });

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
})();
