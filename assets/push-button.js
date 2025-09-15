(function(){
  window.OneSignal = window.OneSignal || [];
  function el(id){ return document.getElementById(id); }
  const btn = el('wcof-push-btn');
  const status = el('wcof-push-status');
  const isAdmin = window.WCOF_PUSH && WCOF_PUSH.isAdmin;
  const defaults = {
    enable: '🔔 Enable notifications',
    enable_admin: '🔔 Enable admin notifications',
    disable: '🔕 Disable notifications',
    disable_admin: '🔕 Disable admin notifications',
    status_subscribed: 'Subscribed',
    status_not_subscribed: 'Not subscribed',
    status_admin_subscribed: 'Admin subscribed',
    status_admin_not_subscribed: 'Admin not subscribed'
  };
  const localized = (window.WCOF_PUSH_BTN && WCOF_PUSH_BTN.labels) || {};
  const getLabel = function(key){ return localized[key] || defaults[key]; };
  const enableLabel  = isAdmin ? getLabel('enable_admin') : getLabel('enable');
  const disableLabel = isAdmin ? getLabel('disable_admin') : getLabel('disable');
  const statusLabels = {
    on:  isAdmin ? getLabel('status_admin_subscribed') : getLabel('status_subscribed'),
    off: isAdmin ? getLabel('status_admin_not_subscribed') : getLabel('status_not_subscribed')
  };

  function updateUI(enabled){
    if(status){
      status.textContent = enabled ? statusLabels.on : statusLabels.off;
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
