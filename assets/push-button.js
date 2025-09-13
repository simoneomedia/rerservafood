(function(){
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

  function refresh(){
    if(!window.OneSignal){ setTimeout(refresh, 400); return; }
    OneSignal.push(function(){
      OneSignal.isPushNotificationsEnabled(function(enabled){
        updateUI(enabled);
      });
    });
  }

  if(btn){
    btn.addEventListener('click', function(){
      if(!window.OneSignal) return;
      OneSignal.push(function(){
        OneSignal.isPushNotificationsEnabled(function(enabled){
          if(enabled){
            OneSignal.setSubscription(false);
            setTimeout(refresh, 800);
          } else {
            if(window.WCOF_PUSH && WCOF_PUSH.userId){
              OneSignal.setExternalUserId(String(WCOF_PUSH.userId));
            }
            OneSignal.showSlidedownPrompt();
            setTimeout(refresh, 1200);
          }
        });
      });
    });
  }

  setTimeout(refresh, 800);
})();