(function(){
  const root = document.getElementById('wcof-push-debug');
  if(!root) return;
  function line(k,v){ return `<div><strong>${k}:</strong> <code>${String(v)}</code></div>`; }
  function render(info){
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
      ${line('Tags', JSON.stringify(info.tags||{}))}
    `;
    document.getElementById('wcof-btn-prompt').onclick = function(){ OneSignal.showSlidedownPrompt(); };
    document.getElementById('wcof-btn-refresh').onclick = load;
    document.getElementById('wcof-btn-unsub').onclick = function(){ OneSignal.setSubscription(false); setTimeout(load,600); };
  }
  function load(){
    if(!window.OneSignal){ setTimeout(load,500); return; }
    OneSignal.push(function(){
      Promise.all([
        OneSignal.getNotificationPermission(),
        new Promise(res=>OneSignal.isPushNotificationsEnabled(res)),
        OneSignal.getUserId(),
        OneSignal.getExternalUserId ? OneSignal.getExternalUserId() : Promise.resolve(null),
        OneSignal.getTags ? OneSignal.getTags() : Promise.resolve({})
      ]).then(function(r){
        render({ permission:r[0], enabled:r[1], userId:r[2], externalId:r[3], tags:r[4] });
      });
    });
  }
  setTimeout(load, 1000);
})();