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

  function ensureQueue(){
    if(!window.OneSignal) window.OneSignal = [];
  }

  function withOneSignal(callback){
    ensureQueue();
    return new Promise(function(resolve){
      var execute = function(){
        try {
          var result = callback(window.OneSignal);
          if(result && typeof result.then === 'function'){
            result.then(resolve).catch(function(error){
              console.error('[OneSignal] Callback rejected.', error);
              resolve();
            });
          } else {
            resolve(result);
          }
        } catch (error) {
          console.error('[OneSignal] Callback threw an error.', error);
          resolve();
        }
      };
      if(typeof window.OneSignal.push === 'function' && window.OneSignal.push !== Array.prototype.push){
        execute();
      } else {
        window.OneSignal.push(execute);
      }
    });
  }

  function resolveValue(value){
    if(typeof value === 'function'){
      try {
        return resolveValue(value());
      } catch (error) {
        return Promise.reject(error);
      }
    }
    if(value && typeof value.then === 'function'){
      return value;
    }
    return Promise.resolve(value);
  }

  function flagIsTrue(value) {
    return value === true || value === 1 || value === '1';
  }

  function stringOrDefault(value, fallback) {
    if (typeof value === 'string' && value) {
      return value;
    }
    return fallback;
  }

  function normalizeUserId(value) {
    var parsed = parseInt(value, 10);
    if (isNaN(parsed) || parsed <= 0) {
      return null;
    }
    return String(parsed);
  }

  function setPushSubscription(target, apiInstance){
    var desired = !!target;
    function perform(api){
      if(!api) return Promise.resolve(null);
      try {
        var pushSub = api.User && api.User.PushSubscription ? api.User.PushSubscription : null;
        if(pushSub){
          if(desired && typeof pushSub.optIn === 'function'){
            return resolveValue(function(){ return pushSub.optIn(); });
          }
          if(!desired && typeof pushSub.optOut === 'function'){
            return resolveValue(function(){ return pushSub.optOut(); });
          }
        }
        if(typeof api.setSubscription === 'function'){
          return resolveValue(function(){ return api.setSubscription(desired); });
        }
        if(desired && typeof api.registerForPushNotifications === 'function'){
          return resolveValue(function(){ return api.registerForPushNotifications({ modalPrompt: false }); });
        }
      } catch (error) {
        return Promise.reject(error);
      }
      return Promise.resolve(null);
    }
    function handle(api){
      return perform(api).catch(function(error){
        console.error('[OneSignal] Failed to update push subscription state.', error);
      });
    }
    if(apiInstance){
      return handle(apiInstance);
    }
    return withOneSignal(handle);
  }

  function fetchPushInfo(){
    return withOneSignal(function(api){
      var pushSub = api && api.User && api.User.PushSubscription ? api.User.PushSubscription : null;

      var permissionPromise = resolveValue(function(){
        if(api && api.Notifications){
          if(typeof api.Notifications.permission === 'function' || typeof api.Notifications.permission === 'string'){
            return api.Notifications.permission;
          }
          if(typeof api.Notifications.getPermission === 'function'){
            return api.Notifications.getPermission();
          }
        }
        if(typeof api.getNotificationPermission === 'function'){
          return api.getNotificationPermission();
        }
        if(typeof Notification !== 'undefined'){
          return Notification.permission;
        }
        return 'default';
      }).catch(function(error){
        console.error('[OneSignal] Failed to read notification permission.', error);
        return typeof Notification !== 'undefined' ? Notification.permission : 'default';
      });

      var enabledPromise = resolveValue(function(){
        if(pushSub){
          if(typeof pushSub.optedIn === 'function'){
            return pushSub.optedIn();
          }
          if(typeof pushSub.optedIn !== 'undefined'){
            return !!pushSub.optedIn;
          }
        }
        if(api && api.Notifications && typeof api.Notifications.isSubscribed === 'function'){
          return api.Notifications.isSubscribed();
        }
        if(typeof api.isPushNotificationsEnabled === 'function'){
          return new Promise(function(resolve){ api.isPushNotificationsEnabled(resolve); });
        }
        return false;
      }).catch(function(error){
        console.error('[OneSignal] Failed to determine push subscription status.', error);
        return false;
      });

      var userIdPromise = resolveValue(function(){
        if(pushSub){
          if(typeof pushSub.id === 'function'){
            return pushSub.id();
          }
          if(typeof pushSub.id !== 'undefined'){
            return pushSub.id;
          }
        }
        if(typeof api.getUserId === 'function'){
          return api.getUserId();
        }
        return null;
      }).catch(function(error){
        console.error('[OneSignal] Failed to get OneSignal user ID.', error);
        return null;
      });

      var externalIdPromise = resolveValue(function(){
        if(api && api.User && api.User.ExternalId && typeof api.User.ExternalId.get === 'function'){
          return api.User.ExternalId.get();
        }
        if(typeof api.getExternalUserId === 'function'){
          return api.getExternalUserId();
        }
        return null;
      }).catch(function(error){
        console.error('[OneSignal] Failed to get external user ID.', error);
        return null;
      });

      var tagsPromise = resolveValue(function(){
        if(api && api.User && api.User.Tags){
          if(typeof api.User.Tags.getTags === 'function'){
            return api.User.Tags.getTags();
          }
          if(typeof api.User.Tags.getAll === 'function'){
            return api.User.Tags.getAll();
          }
        }
        if(typeof api.getTags === 'function'){
          return api.getTags();
        }
        return {};
      }).catch(function(error){
        console.error('[OneSignal] Failed to get OneSignal tags.', error);
        return {};
      });

      return Promise.all([permissionPromise, enabledPromise, userIdPromise, externalIdPromise, tagsPromise]).then(function(values){
        var tags = values[4];
        if(!tags || typeof tags !== 'object'){
          tags = {};
        }
        return {
          permission: values[0] || (typeof Notification !== 'undefined' ? Notification.permission : 'default'),
          enabled: !!values[1],
          userId: values[2] || null,
          externalId: values[3] || null,
          tags: tags
        };
      });
    });
  }

  function requestPushPermission(){
    try {
      withOneSignal(function(api){
        if(!api) return;
        if(api.Notifications && typeof api.Notifications.requestPermission === 'function'){
          var result = null;
          try {
            result = api.Notifications.requestPermission(true);
          } catch (err) {
            console.error('[OneSignal] Failed to request permission via Notifications.requestPermission.', err);
          }
          var ensureSubscribed = function(outcome){
            var granted = false;
            if(outcome === 'granted' || outcome === true){
              granted = true;
            } else if(outcome && typeof outcome === 'object'){
              if(outcome.state === 'granted' || outcome.permission === 'granted' || outcome.to === 'granted'){
                granted = true;
              }
            }
            if(!granted && typeof Notification !== 'undefined' && Notification.permission === 'granted'){
              granted = true;
            }
            if(granted){
              setPushSubscription(true, api);
            }
          };
          if(result && typeof result.then === 'function'){
            result.then(ensureSubscribed).catch(function(error){
              console.error('[OneSignal] Permission request promise rejected.', error);
              ensureSubscribed(null);
            });
          } else {
            ensureSubscribed(result);
          }
          return;
        }
        if(api.Slidedown && typeof api.Slidedown.promptPush === 'function'){
          api.Slidedown.promptPush();
          return;
        }
        if(typeof api.showSlidedownPrompt === 'function'){
          api.showSlidedownPrompt();
          return;
        }
        if(typeof api.registerForPushNotifications === 'function'){
          api.registerForPushNotifications({ modalPrompt: true });
        }
      });
    } catch (error) {
      console.error('[OneSignal] Failed to request push permission.', error);
    }
    if(typeof Notification !== 'undefined' && Notification.permission === 'granted'){
      setPushSubscription(true);
    }
  }

  window.wcofRequestPushPermission = requestPushPermission;
  window.wcofSetPushSubscription = function(enabled){
    return setPushSubscription(enabled);
  };
  window.wcofFetchPushInfo = fetchPushInfo;

  var serviceWorkerScope = stringOrDefault(WCOF_PUSH.swScope, '/');
  var serviceWorkerPath = stringOrDefault(WCOF_PUSH.swWorkerPath, '/OneSignalSDKWorker.js');
  var serviceWorkerUpdaterPath = stringOrDefault(WCOF_PUSH.swUpdaterPath, '/OneSignalSDKUpdaterWorker.js');

  OneSignal.push(function() {
    OneSignal.init({
      appId: WCOF_PUSH.appId,
      serviceWorkerParam: { scope: serviceWorkerScope },
      serviceWorkerPath: serviceWorkerPath,
      serviceWorkerUpdaterPath: serviceWorkerUpdaterPath,
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

    try {
      if(OneSignal.Notifications && typeof OneSignal.Notifications.addEventListener === 'function'){
        OneSignal.Notifications.addEventListener('permissionChange', function(event){
          if(event && (event.to === 'granted' || event.permission === 'granted')){
            setPushSubscription(true, OneSignal);
          }
        });
      } else if (typeof OneSignal.on === 'function') {
        OneSignal.on('notificationPermissionChange', function(permissionChange){
          if(permissionChange && permissionChange.to === 'granted'){
            setPushSubscription(true, OneSignal);
          }
        });
      }
    } catch (error) {
      console.error('[OneSignal] Failed to attach permission change listener.', error);
    }
  });

  // automatic prompt on first click, but we also provide a manual button
  document.addEventListener('click', function once(){
    requestPushPermission();
    document.removeEventListener('click', once);
  });
})();
