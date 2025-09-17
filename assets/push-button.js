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
    if(btn){
      btn.textContent = enabled ? disableLabel : enableLabel;
      btn.disabled = false;
    }
  }

  const registrationWarning = 'Service worker registration failed – check /OneSignalSDKWorker.js and /OneSignalSDKUpdaterWorker.js';

  function requestPushPermission(){
    if (typeof window.wcofRequestPushPermission === 'function') {
      window.wcofRequestPushPermission();
      return;
    }
    try {
      if(!window.OneSignal){ return; }
      window.OneSignal.push(function(){
        var api = window.OneSignal;
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
      });
    } catch (error) {
      console.error('[OneSignal] Failed to request push permission from button click.', error);
    }
  }

  function setSubscription(enabled){
    if (typeof window.wcofSetPushSubscription === 'function') {
      return window.wcofSetPushSubscription(enabled);
    }
    if(!window.OneSignal){ return Promise.resolve(); }
    return new Promise(function(resolve){
      window.OneSignal.push(function(){
        try {
          if(typeof window.OneSignal.setSubscription === 'function'){
            window.OneSignal.setSubscription(!!enabled);
          } else if (enabled && typeof window.OneSignal.registerForPushNotifications === 'function'){
            window.OneSignal.registerForPushNotifications();
          }
        } finally {
          resolve();
        }
      });
    });
  }

  function fetchInfo(){
    if (typeof window.wcofFetchPushInfo === 'function') {
      return window.wcofFetchPushInfo().catch(function(error){
        console.error('[OneSignal] Failed to fetch push info for button UI.', error);
        return null;
      });
    }
    if(!window.OneSignal){
      return Promise.resolve({
        permission: typeof Notification !== 'undefined' ? Notification.permission : 'default',
        enabled: false
      });
    }
    return new Promise(function(resolve){
      window.OneSignal.push(function(){
        var api = window.OneSignal;
        var permissionFallback = typeof Notification !== 'undefined' ? Notification.permission : 'default';
        var getPermission = typeof api.getNotificationPermission === 'function'
          ? api.getNotificationPermission()
          : Promise.resolve(permissionFallback);
        var getEnabled = typeof api.isPushNotificationsEnabled === 'function'
          ? new Promise(function(res){ api.isPushNotificationsEnabled(res); })
          : Promise.resolve(false);
        Promise.all([getPermission, getEnabled]).then(function(values){
          resolve({ permission: values[0], enabled: !!values[1] });
        }).catch(function(error){
          console.error('[OneSignal] Failed to read push state.', error);
          resolve(null);
        });
      });
    });
  }

  function refresh(){
    if(typeof Notification === 'undefined'){
      if(status){
        status.textContent = 'Push notifications are not supported on this device.';
      }
      if(btn){
        btn.disabled = true;
      }
      return;
    }
    fetchInfo().then(function(info){
      if(!info){
        console.warn('[OneSignal] Unable to determine push state. Retrying…');
        setTimeout(refresh, 1000);
        return;
      }
      var permission = info.permission || (typeof Notification !== 'undefined' ? Notification.permission : 'default');
      var enabled = !!info.enabled;
      updateUI(enabled);
      if(permission === 'granted' && !enabled){
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
  }

  if(btn){
    btn.addEventListener('click', function(){
      console.log('[OneSignal] Push button clicked; determining subscription status.');
      if(typeof Notification === 'undefined'){
        if(status){
          status.textContent = 'Push notifications are not supported on this device.';
        }
        console.warn('[OneSignal] Notifications API is not available in this browser.');
        return;
      }
      fetchInfo().then(function(info){
        if(!info){
          console.warn('[OneSignal] Unable to determine push state when button clicked. Retrying refresh.');
          refresh();
          return;
        }
        var permission = info.permission || (typeof Notification !== 'undefined' ? Notification.permission : 'default');
        var enabled = !!info.enabled;
        if(enabled){
          Promise.resolve(setSubscription(false)).then(function(){
            setTimeout(refresh, 500);
          });
          return;
        }
        if(window.WCOF_PUSH && WCOF_PUSH.userId && window.OneSignal){
          var ensureExternalId = function(){
            try {
              if(typeof window.OneSignal.setExternalUserId === 'function'){
                window.OneSignal.setExternalUserId(String(WCOF_PUSH.userId));
              }
            } catch (error) {
              console.error('[OneSignal] Failed to set external user ID before subscribing.', error);
            }
          };
          if(typeof window.OneSignal.push === 'function'){
            window.OneSignal.push(ensureExternalId);
          } else {
            ensureExternalId();
          }
        }
        if(permission === 'granted'){
          Promise.resolve(setSubscription(true)).then(function(){
            setTimeout(refresh, 500);
          });
        } else if(permission === 'denied'){
          if(status){
            status.textContent = 'Push notifications are blocked in your browser settings.';
          }
          console.warn('[OneSignal] Notification permission previously denied by the user.');
        } else {
          requestPushPermission();
        }
      });
    });
  }

  if(window.OneSignal && typeof window.OneSignal.push === 'function'){
    window.OneSignal.push(function(){
      if(typeof window.OneSignal.on === 'function'){
        window.OneSignal.on('subscriptionChange', refresh);
      }
      if(window.OneSignal.Notifications && typeof window.OneSignal.Notifications.addEventListener === 'function'){
        try {
          window.OneSignal.Notifications.addEventListener('permissionChange', function(){
            setTimeout(refresh, 300);
          });
        } catch (error) {
          console.error('[OneSignal] Failed to listen for permission changes.', error);
        }
      }
    });
  }

  refresh();
})();
