/**
 * Checkout address helper.
 *
 * Initializes Leaflet map and address search once the DOM is ready.
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
        var validInput = document.querySelector('#wcof_delivery_valid');
        var resolvedInput = document.querySelector('#wcof_delivery_resolved');
        var errorEl = document.getElementById('wcof-delivery-error');
        var dragLink = document.getElementById('wcof-move-marker');
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
        var editing = false;

        function showError(msg){
            if(errorEl){
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
            }
        }
        function hideError(){
            if(errorEl){
                errorEl.textContent = '';
                errorEl.style.display = 'none';
            }
        }

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
                        showError('Address not in delivery area or not found');
                        if(validInput) validInput.value='';
                        if(resolvedInput) resolvedInput.value='';
                        document.querySelector('#billing_state').value = '';
                        document.querySelector('#shipping_state').value = '';
                        return;
                    }
                    hideError();
                    var full = data.display_name || '';
                    if(resolvedInput) resolvedInput.value = full;
                    document.querySelector('#billing_postcode').value = pc;
                    document.querySelector('#billing_address_1').value = full;
                    document.querySelector('#billing_city').value = addr.city || addr.town || addr.village || '';
                    document.querySelector('#billing_country').value = (addr.country_code || '').toUpperCase();
                    document.querySelector('#billing_state').value = '';
                    document.querySelector('#shipping_state').value = '';
                    lastValid = latlng;
                    if(validInput) validInput.value='1';
                });
        }

        function placeMarker(lat, lon, resolve){
            var latlng = Leaflet.latLng(lat, lon);
            if(!marker){
                marker = Leaflet.marker(latlng, {draggable:false}).addTo(map);
                marker.on('dragend', function(e){
                    if(!editing){
                        reverseAndFill(e.target.getLatLng());
                    }
                });
            }else{
                marker.setLatLng(latlng);
            }
            map.setView(latlng, 16);
            if(resolve !== false){
                reverseAndFill(latlng);
            }
        }

        function searchAddress(){
            var q = input.value;
            if(q.length < 3) return;
            fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=1&q='+encodeURIComponent(q))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if(!Array.isArray(data) || !data.length){
                        showError('Address not in delivery area or not found');
                        document.querySelector('#billing_state').value = '';
                        document.querySelector('#shipping_state').value = '';
                        return;
                    }
                    var item = data[0];
                    if(!item.address || !item.address.postcode || (allowed.length && allowed.indexOf(item.address.postcode) === -1)){
                        showError('Address not in delivery area or not found');
                        document.querySelector('#billing_state').value = '';
                        document.querySelector('#shipping_state').value = '';
                        return;
                    }
                    hideError();
                    placeMarker(item.lat, item.lon);
                });
        }

        input.addEventListener('input', function(){
            if(validInput) validInput.value='';
            if(resolvedInput) resolvedInput.value='';
            hideError();
        });
        input.addEventListener('change', searchAddress);
        input.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                e.preventDefault();
                searchAddress();
            }
        });

        map.on('click', function(e){
            if(editing){
                placeMarker(e.latlng.lat, e.latlng.lng, false);
            }
        });

        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.id = 'wcof-confirm-marker';
        confirmBtn.textContent = 'Confirm marker';
        confirmBtn.style.display = 'none';
        confirmBtn.style.marginBottom = '10px';
        mapEl.parentNode.insertBefore(confirmBtn, mapEl);

        confirmBtn.addEventListener('click', function(e){
            e.preventDefault();
            editing = false;
            confirmBtn.style.display = 'none';
            if(marker){
                marker.dragging.disable();
                reverseAndFill(marker.getLatLng());
            }
        });

        if(dragLink){
            dragLink.addEventListener('click', function(e){
                e.preventDefault();
                editing = true;
                if(validInput) validInput.value='';
                if(resolvedInput) resolvedInput.value='';
                if(marker) marker.dragging.enable();
                confirmBtn.style.display = 'block';
            });
        }

        var heading=document.querySelector('.woocommerce-billing-fields > h3');
        if(heading) heading.style.display='none';
        var ship=document.querySelector('.woocommerce-shipping-fields');
        if(ship) ship.style.display='none';
    }

    // Wait for the DOM to be fully loaded before initializing.
    document.addEventListener('DOMContentLoaded', init);
})();
