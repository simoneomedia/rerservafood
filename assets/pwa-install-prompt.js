(function(){
    var deferredPrompt;

    function createBanner(){
        if(document.getElementById('wcof-install-banner')) return;
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
    }

    window.addEventListener('beforeinstallprompt', function(e){
        e.preventDefault();
        deferredPrompt = e;
        createBanner();
    });

    if(/iphone|ipad|ipod|android/i.test(navigator.userAgent)){
        window.addEventListener('load', function(){
            setTimeout(function(){
                if(!deferredPrompt && !window.matchMedia('(display-mode: standalone)').matches){
                    createBanner();
                }
            }, 3000);
        });
    }
})();
