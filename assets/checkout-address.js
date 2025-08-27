/**
 * Checkout address helper.
 *
 * Initializes Leaflet map and address autocomplete once the DOM is ready.
 * The original file defined `init` but never executed it and left a stray
 * closing `);` which broke the script and could prevent jQuery or other
 * scripts from running properly.
 */
(function(){
    if(typeof wcofCheckoutAddress === 'undefined') return;

    // Capture Leaflet early to avoid conflicts with other scripts that may
    // reuse the global `L` variable.
    var Leaflet = window.L;

    function init(){
        if(!Leaflet || typeof Leaflet.map !== 'function') return;

        var allowed = wcofCheckoutAddress.postalCodes || [];
        var input = document.querySelector('#wcof_delivery_address');
        if(!input) return;
        var datalist = document.createElement('datalist');
        datalist.id = 'wcof-address-list';
        document.body.appendChild(datalist);
        input.setAttribute('list', datalist.id);
        var mapEl = document.getElementById('wcof-delivery-map');
        if(!mapEl) return;
        var map = Leaflet.map(mapEl);
        Leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // Leaflet calculates the initial map size during construction. When the
        // container is hidden (e.g. inside a collapsed section) this size ends
        // up being zero and the map renders incorrectly once shown.  Observe
        // visibility changes and invalidate the size when the container becomes
        // visible so tiles and marker positions are recalculated correctly.
        function ensureVisible(){
            if(!mapEl) return;
            if(mapEl.offsetParent !== null){
                setTimeout(function(){ map.invalidateSize(); }, 0);
                return true;
            }
            return false;
        }
        if(!ensureVisible()){
            var obs = new MutationObserver(function(){
                if(ensureVisible()) obs.disconnect();
            });
            obs.observe(mapEl, {attributes:true, attributeFilter:['style','class']});
        }
        window.addEventListener('resize', function(){ map.invalidateSize(); });

        var marker = null;
        var lastValid = null;
        var suggestions = [];

        // Always show a world view without restricting map bounds. Postal code
        // limits are checked only after the user selects an address.
        map.setView([0, 0], 2);

        function reverseAndFill(latlng){
            fetch('https://nominatim.openstreetmap.org/reverse?format=json&addressdetails=1&lat='+latlng.lat+'&lon='+latlng.lng)
                .then(function(r){return r.json();})
                .then(function(data){
                    var addr = data.address || {};
                    var pc = addr.postcode || '';
                    if(allowed.length && allowed.indexOf(pc) === -1){
                        alert('Invalid address');
                        if(lastValid){ marker.setLatLng(lastValid); }
                        return;
                    }
                    document.querySelector('#billing_postcode').value = pc;
                    document.querySelector('#billing_address_1').value = input.value;
                    document.querySelector('#billing_city').value = addr.city || addr.town || addr.village || '';
                    document.querySelector('#billing_country').value = (addr.country_code || '').toUpperCase();
                    lastValid = latlng;
                });
        }

        function placeMarker(lat, lon){
            var latlng = Leaflet.latLng(lat, lon);
            if(!marker){
                marker = Leaflet.marker(latlng, {draggable:true}).addTo(map);
                marker.on('dragend', function(e){ reverseAndFill(e.target.getLatLng()); });
            }else{
                marker.setLatLng(latlng);
            }
            map.setView(latlng, 16);
            reverseAndFill(latlng);
        }

        input.addEventListener('input', function(){
            var q = input.value;
            if(q.length < 3) return;
            fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&q='+encodeURIComponent(q))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    datalist.innerHTML='';
                    suggestions=[];
                    data.forEach(function(item, idx){
                        if(!item.address || !item.address.postcode) return;
                        var opt = document.createElement('option');
                        opt.value = item.display_name;
                        opt.setAttribute('data-idx', idx);
                        datalist.appendChild(opt);
                        suggestions[idx]=item;
                    });
                });
        });

        input.addEventListener('change', function(){
            var opt = Array.from(datalist.options).find(function(o){ return o.value === input.value; });
            if(opt){
                var item = suggestions[opt.getAttribute('data-idx')];
                if(item){ placeMarker(item.lat, item.lon); }
            }
        });

        map.on('click', function(e){
            placeMarker(e.latlng.lat, e.latlng.lng);
        });

        var heading=document.querySelector('.woocommerce-billing-fields > h3');
        if(heading) heading.style.display='none';
        var ship=document.querySelector('.woocommerce-shipping-fields');
        if(ship) ship.style.display='none';
    }

    // Wait for the DOM to be fully loaded before initializing.
    document.addEventListener('DOMContentLoaded', init);
})();
