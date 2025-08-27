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
        var mapEl = document.getElementById('wcof-delivery-map');
        if(!mapEl) return;
        var map = Leaflet.map(mapEl);
        Leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var form = input.form || input.closest('form');
        var errorEl = document.createElement('div');
        errorEl.style.color = '#dc2626';
        errorEl.style.fontSize = '0.9em';
        errorEl.style.marginTop = '4px';
        errorEl.style.display = 'none';
        input.insertAdjacentElement('afterend', errorEl);
        var submitBtn = form ? form.querySelector('#place_order') : null;
        if(submitBtn) submitBtn.disabled = true;
        function setError(msg){
            errorEl.textContent = msg || '';
            errorEl.style.display = msg ? 'block' : 'none';
            if(submitBtn) submitBtn.disabled = !!msg;
        }

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
        var deliveryRings = [];
        var highlightPolys = [];

        function extractRings(geom){
            var rings = [];
            function toLatLngRing(coords){ return coords.map(function(pt){ return [pt[1], pt[0]]; }); }
            if(!geom) return rings;
            if(geom.type === 'Polygon'){
                rings.push(toLatLngRing(geom.coordinates[0]));
            }else if(geom.type === 'MultiPolygon'){
                geom.coordinates.forEach(function(poly){ rings.push(toLatLngRing(poly[0])); });
            }
            return rings;
        }

        function loadDeliveryAreas(){
            if(!allowed.length) return;
            var reqs = allowed.map(function(pc){
                return fetch('https://nominatim.openstreetmap.org/search?format=geojson&polygon_geojson=1&limit=1&postalcode='+encodeURIComponent(pc))
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if(!data.features || !data.features.length) return;
                        var feature = data.features[0];
                        var poly = Leaflet.geoJSON(feature.geometry, {color:'#2563eb', weight:2, fillOpacity:0}).addTo(map);
                        highlightPolys.push(poly);
                        deliveryRings = deliveryRings.concat(extractRings(feature.geometry));
                    });
            });
            Promise.all(reqs).then(function(){
                if(!deliveryRings.length) return;
                var world = [[-90,-180],[-90,180],[90,180],[90,-180]];
                Leaflet.polygon([world].concat(deliveryRings), {
                    stroke:false,
                    color:'#000',
                    fillColor:'#000',
                    fillOpacity:0.5,
                    interactive:false
                }).addTo(map);
                var group = Leaflet.featureGroup(highlightPolys);
                map.fitBounds(group.getBounds().pad(0.5));
            });
        }

        // Always show a world view without restricting map bounds. Postal code
        // limits are checked only after the user selects an address.
        map.setView([0, 0], 2);
        loadDeliveryAreas();

        function reverseAndFill(latlng){
            fetch('https://nominatim.openstreetmap.org/reverse?format=json&addressdetails=1&lat='+latlng.lat+'&lon='+latlng.lng)
                .then(function(r){return r.json();})
                .then(function(data){
                    var addr = data.address || {};
                    var pc = addr.postcode || '';
                    if(allowed.length && allowed.indexOf(pc) === -1){
                        setError('Delivery not available in this area');
                        input.setCustomValidity('Invalid delivery address');
                        input.reportValidity();
                        if(lastValid){ marker.setLatLng(lastValid); map.setView(lastValid, 16); }
                        return;
                    }
                    setError('');
                    input.setCustomValidity('');
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

        function searchAddress(){
            var q = input.value;
            if(q.length < 3) return;
            fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=1&q='+encodeURIComponent(q))
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if(!Array.isArray(data) || !data.length) return;
                    var item = data[0];
                    if(!item.address || !item.address.postcode){
                        setError('Address not found');
                        input.setCustomValidity('Address not found');
                        input.reportValidity();
                        return;
                    }
                    if(allowed.length && allowed.indexOf(item.address.postcode) === -1){
                        setError('Delivery not available in this area');
                        input.setCustomValidity('Invalid delivery address');
                        input.reportValidity();
                        return;
                    }
                    placeMarker(item.lat, item.lon);
                });
        }

        input.addEventListener('change', searchAddress);
        input.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                e.preventDefault();
                searchAddress();
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

    // Initialize when the DOM is ready. If this script is loaded after
    // `DOMContentLoaded` has already fired (e.g. injected in the footer), the
    // previous implementation would never run `init` and the map would not
    // appear.  Check `readyState` so `init` is invoked immediately when the
    // DOM is already parsed.
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', init);
    }else{
        init();
    }
})();
