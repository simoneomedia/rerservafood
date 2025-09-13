(function(){
    var deferredPrompt;
    window.addEventListener('beforeinstallprompt', function(e){
        e.preventDefault();
        deferredPrompt = e;
        var banner = document.createElement('div');
        banner.id = 'wcof-install-banner';
        var button = document.createElement('button');
        button.id = 'wcof-install-button';
        button.textContent = (window.wcofPwaPrompt && window.wcofPwaPrompt.text) ? window.wcofPwaPrompt.text : 'Download the app';
        banner.appendChild(button);
        document.body.appendChild(banner);
        button.addEventListener('click', function(){
            banner.parentNode.removeChild(banner);
            if(deferredPrompt){
                deferredPrompt.prompt();
                deferredPrompt = null;
            }
        });
    });
})();
