(function(){
  window.OneSignal = window.OneSignal || [];
  function el(id){ return document.getElementById(id); }
  const btn = el('wcof-push-btn');
  const status = el('wcof-push-status');
  const isAdmin = window.WCOF_PUSH && WCOF_PUSH.isAdmin;
  const enableLabel  = isAdmin ? '🔔 Enable admin notifications' : '🔔 Enable notifications';
  const disableLabel = isAdmin ? '🔕 Disable admin notifications' : '🔕 Disable notifications';

  function updateUI(enabled){
    if(status){
      status.textContent = enabled
        ? (isAdmin ? 'Admin subscribed' : 'Subscribed')
        : (isAdmin ? 'Admin not subscribed' : 'Not subscribed');
    }
    if(btn) btn.textContent = enabled ? disableLabel : enableLabel;
  }

  const registrationWarning = 'Service worker registration failed – check /OneSignalSDKWorker.js';

  function refresh(){
    OneSignal.push(function(){
      OneSignal.isPushNotificationsEnabled(function(enabled){
        updateUI(enabled);

        if(typeof Notification !== 'undefined' && Notification.permission === 'granted' && !enabled){
          console.warn(registrationWarning);

          if(status){
            const current = status.textContent || '';
            if(current.indexOf(registrationWarning) === -1){
              status.textContent = current
                ? current + ' (' + registrationWarning + ')'
                : registrationWarning;
            }
          }
        }
      });
    });
  }

  if(btn){
    btn.addEventListener('click', function(){
      OneSignal.push(function(){
        OneSignal.isPushNotificationsEnabled(function(enabled){
          if(enabled){
            OneSignal.setSubscription(false);
          } else {
            if(window.WCOF_PUSH && WCOF_PUSH.userId){
              OneSignal.setExternalUserId(String(WCOF_PUSH.userId));
            }
            if(Notification.permission === 'granted'){
              if(typeof OneSignal.registerForPushNotifications === 'function'){
                OneSignal.registerForPushNotifications();
              } else {
                OneSignal.setSubscription(true);
              }
            } else {
              OneSignal.showSlidedownPrompt();
            }
          }
        });
      });
    });
  }

  OneSignal.push(function(){
    OneSignal.on('subscriptionChange', refresh);
  });

  refresh();
})();
