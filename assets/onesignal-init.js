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
  document.head.appendChild(s);

  OneSignal.push(function() {
    OneSignal.init({
      appId: WCOF_PUSH.appId,
      serviceWorkerParam: { scope: '/' },
      serviceWorkerPath: '/OneSignalSDKWorker.js',
      serviceWorkerUpdaterPath: '/OneSignalSDKUpdaterWorker.js',
      allowLocalhostAsSecureOrigin: true,
      notifyButton: { enable: false }
    });
    if(WCOF_PUSH.isAdmin){ OneSignal.sendTag('wcof_role','admin'); }
    else { OneSignal.sendTag('wcof_role','user'); }
    if(WCOF_PUSH.userId){ OneSignal.setExternalUserId(String(WCOF_PUSH.userId)); }
  });

  // automatic prompt on first click, but we also provide a manual button
  document.addEventListener('click', function once(){
    OneSignal.push(function(){ OneSignal.showSlidedownPrompt(); });
    document.removeEventListener('click', once);
  });
})();