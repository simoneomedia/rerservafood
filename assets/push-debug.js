(function(){
  const root = document.getElementById('wcof-push-debug');
  if(!root) return;

  function line(k,v){
    return `<div><strong>${k}:</strong> <code>${String(v)}</code></div>`;
  }

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
      console.error('[OneSignal] Failed to request push permission from debug tools.', error);
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
        console.error('[OneSignal] Failed to fetch push info for debug tools.', error);
        return null;
      });
    }
    if(!window.OneSignal){
      return Promise.resolve({
        permission: typeof Notification !== 'undefined' ? Notification.permission : 'default',
        enabled: false,
        userId: null,
        externalId: null,
        tags: {}
      });
    }
    return new Promise(function(resolve){
      window.OneSignal.push(function(){
        var api = window.OneSignal;
        var getPermission = typeof api.getNotificationPermission === 'function'
          ? api.getNotificationPermission()
          : Promise.resolve(typeof Notification !== 'undefined' ? Notification.permission : 'default');
        var getEnabled = typeof api.isPushNotificationsEnabled === 'function'
          ? new Promise(function(res){ api.isPushNotificationsEnabled(res); })
          : Promise.resolve(false);
        var getUserId = typeof api.getUserId === 'function' ? api.getUserId() : Promise.resolve(null);
        var getExternal = typeof api.getExternalUserId === 'function' ? api.getExternalUserId() : Promise.resolve(null);
        var getTags = typeof api.getTags === 'function' ? api.getTags() : Promise.resolve({});
        Promise.all([getPermission, getEnabled, getUserId, getExternal, getTags]).then(function(values){
          resolve({
            permission: values[0],
            enabled: !!values[1],
            userId: values[2],
            externalId: values[3],
            tags: values[4] || {}
          });
        }).catch(function(error){
          console.error('[OneSignal] Failed to load debug info.', error);
          resolve(null);
        });
      });
    });
  }

  function render(info){
    info = info || { permission: 'unknown', enabled: false, userId: null, externalId: null, tags: {} };
    root.innerHTML = `
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <button id="wcof-btn-prompt" class="button button-primary">Prompt</button>
        <button id="wcof-btn-refresh" class="button">Refresh</button>
        <button id="wcof-btn-unsub" class="button">Unsubscribe</button>
      </div>
      ${line('Permission', info.permission)}
      ${line('Enabled', info.enabled)}
      ${line('Player ID', info.userId)}
      ${line('External User ID', info.externalId)}
      ${line('Tags', JSON.stringify(info.tags || {}))}
    `;
    document.getElementById('wcof-btn-prompt').onclick = requestPushPermission;
    document.getElementById('wcof-btn-refresh').onclick = load;
    document.getElementById('wcof-btn-unsub').onclick = function(){
      Promise.resolve(setSubscription(false)).then(function(){
        setTimeout(load, 600);
      });
    };
  }

  function load(){
    fetchInfo().then(function(info){
      if(!info){
        console.warn('[OneSignal] Debug info unavailable. Retrying…');
        setTimeout(load, 1000);
        return;
      }
      render(info);
    });
  }

  setTimeout(load, 1000);
})();
