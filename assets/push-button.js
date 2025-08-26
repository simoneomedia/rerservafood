(function(){
  function el(id){ return document.getElementById(id); }
  const btn = el('wcof-push-btn');
  const status = el('wcof-push-status');
  function setStatus(t){ if(status) status.textContent = t; }
  function refresh(){
    if(!window.OneSignal){ setTimeout(refresh, 400); return; }
    OneSignal.push(function(){
      OneSignal.isPushNotificationsEnabled(function(enabled){
        setStatus(enabled ? 'Subscribed' : 'Not subscribed');
        if(btn) btn.textContent = enabled ? '🔕 Disable notifications' : '🔔 Enable notifications';
      });
    });
  }
  if(btn){
    btn.addEventListener('click', function(){
      if(!window.OneSignal) return;
      OneSignal.push(function(){
        OneSignal.isPushNotificationsEnabled(function(enabled){
          if(enabled){
            OneSignal.setSubscription(false); setTimeout(refresh, 800);
          } else {
            OneSignal.showSlidedownPrompt(); setTimeout(refresh, 1200);
          }
        });
      });
    });
  }
  setTimeout(refresh, 800);
})();