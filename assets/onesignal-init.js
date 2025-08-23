(function(){
  if(!window.WCOF_PUSH || !WCOF_PUSH.appId) return;
  window.OneSignal = window.OneSignal || [];
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