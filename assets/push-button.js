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

  const registrationWarning = 'Service worker registration failed – check /OneSignalSDKWorker.js and /OneSignalSDKUpdaterWorker.js';

  function requestPushPermission(){
    if (typeof window.wcofRequestPushPermission === 'function') {
      window.wcofRequestPushPermission();
      return;
    }
    try {
      var api = window.OneSignal || null;
      if (api && api.Notifications && typeof api.Notifications.requestPermission === 'function') {
        api.Notifications.requestPermission(true);
        return;
      }
      if (api && api.Slidedown && typeof api.Slidedown.promptPush === 'function') {
        api.Slidedown.promptPush();
        return;
      }
      if (api && typeof api.showSlidedownPrompt === 'function') {
        api.showSlidedownPrompt();
        return;
      }
      if (api && typeof api.registerForPushNotifications === 'function') {
        api.registerForPushNotifications({ modalPrompt: true });
      }
    } catch (error) {
      console.error('[OneSignal] Failed to request push permission from button click.', error);
    }
  }

  function refresh(){
    OneSignal.push(function(){
      if(typeof Notification === 'undefined'){
        updateUI(false);
        if(status){
          status.textContent = 'Push notifications are not supported on this device.';
        }
        if(btn){
          btn.disabled = true;
        }
        return;
      }
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
      console.log('[OneSignal] Push button clicked; queuing subscription check.');
      if(typeof Notification === 'undefined'){
        if(status){
          status.textContent = 'Push notifications are not supported on this device.';
        }
        console.warn('[OneSignal] Notifications API is not available in this browser.');
        return;
      }
      OneSignal.push(function(){
        OneSignal.isPushNotificationsEnabled(function(enabled){
          if(enabled){
            OneSignal.setSubscription(false);
          } else {
            if(window.WCOF_PUSH && WCOF_PUSH.userId){
              OneSignal.setExternalUserId(String(WCOF_PUSH.userId));
            }
            var permission = (typeof Notification !== 'undefined' && Notification.permission) ? Notification.permission : 'default';
            if(permission === 'granted'){
              if(typeof OneSignal.registerForPushNotifications === 'function'){
                OneSignal.registerForPushNotifications();
              } else {
                OneSignal.setSubscription(true);
              }
            } else if(permission === 'denied'){
              if(status){
                status.textContent = 'Push notifications are blocked in your browser settings.';
              }
              console.warn('[OneSignal] Notification permission previously denied by the user.');
            } else {
              requestPushPermission();
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
