(function(){
  if(!window.WCOF_PUSH || !WCOF_PUSH.appId) return;
  window.OneSignal = window.OneSignal || [];

  // Load hCaptcha before OneSignal because the SDK expects a global
  // `hcaptcha` object when prompting users for notification permission. On
  // sites where the hCaptcha script was not already present, OneSignal would
  // try to access `hcaptcha` and throw a ReferenceError in the console. By
  // injecting the script here we make sure the object exists whenever the
  // SDK runs.
  var hc = document.createElement('script');
  hc.src = 'https://hcaptcha.com/1/api.js';
  hc.async = true;
  document.head.appendChild(hc);

  var s = document.createElement('script');
  s.src = 'https://cdn.onesignal.com/sdks/OneSignalSDK.js';
  s.async = true;
  try {
    document.head.appendChild(s);
    console.log('[OneSignal] SDK script tag appended to <head>.');
  } catch (error) {
    console.error('[OneSignal] Failed to append SDK script tag to <head>.', error);
  }
  s.addEventListener('load', function(){
    console.log('[OneSignal] SDK script loaded successfully.');
  });
  s.addEventListener('error', function(event){
    console.error('[OneSignal] SDK script failed to load.', event);
  });

  function flagIsTrue(value) {
    return value === true || value === 1 || value === '1';
  }

  function normalizeUserId(value) {
    var parsed = parseInt(value, 10);
    if (isNaN(parsed) || parsed <= 0) {
      return null;
    }
    return String(parsed);
  }

  OneSignal.push(function() {
    OneSignal.init({
      appId: WCOF_PUSH.appId,
      serviceWorkerParam: { scope: '/' },
      serviceWorkerPath: '/OneSignalSDKWorker.js',
      serviceWorkerUpdaterPath: '/OneSignalSDKUpdaterWorker.js',
      allowLocalhostAsSecureOrigin: true,
      notifyButton: { enable: false }
    });

    var isAdmin = flagIsTrue(WCOF_PUSH.isAdmin);
    var isRider = flagIsTrue(WCOF_PUSH.isRider);
    var role = 'user';

    if (isAdmin) {
      role = 'admin';
    } else if (isRider) {
      role = 'rider';
    }

    OneSignal.sendTag('wcof_role', role);

    var externalId = normalizeUserId(WCOF_PUSH.userId);
    if (externalId) {
      OneSignal.setExternalUserId(externalId);
    }
  });

  // automatic prompt on first click, but we also provide a manual button
  document.addEventListener('click', function once(){
    OneSignal.push(function(){ OneSignal.showSlidedownPrompt(); });
    document.removeEventListener('click', once);
  });
})();
