(function(){
    var deferredPrompt;

    function createBanner(){
        if(document.getElementById('wcof-install-banner')) return;
        var banner = document.createElement('div');
        banner.id = 'wcof-install-banner';

        if(deferredPrompt){
            var button = document.createElement('button');
            button.id = 'wcof-install-button';
            button.textContent = (window.wcofPwaPrompt && window.wcofPwaPrompt.text) ? window.wcofPwaPrompt.text : 'Download the app';
            banner.appendChild(button);
            button.addEventListener('click', function(){
                banner.parentNode.removeChild(banner);
                deferredPrompt.prompt();
                deferredPrompt = null;
            });
            document.body.appendChild(banner);
        }else if(window.wcofPwaPrompt && window.wcofPwaPrompt.manual){
            var info = document.createElement('p');
            info.id = 'wcof-install-instructions';
            info.textContent = window.wcofPwaPrompt.manual;
            banner.appendChild(info);
            document.body.appendChild(banner);
        }
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
