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

    function toggleQuickPayButtons(isEnabled){
        var selectors = [
            '.wc-stripe-payment-request-button',
            '.wc-stripe-payment-request-wrapper',
            '.wc-stripe-payment-request-button-separator'
        ];
        selectors.forEach(function(sel){
            var els = document.querySelectorAll(sel);
            els.forEach(function(el){
                if(isEnabled){
                    el.style.display = '';
                    if('disabled' in el) el.disabled = false;
                }else{
                    el.style.display = 'none';
                    if('disabled' in el) el.disabled = true;
                }
            });
        });
    }

    function init(){
        if(!Leaflet || typeof Leaflet.map !== 'function') return;

        toggleQuickPayButtons(false);

        var allowed = wcofCheckoutAddress.postalCodes || [];
        var get = function(id){ return document.getElementById(id); };
        var townInput = get('wcof_delivery_town');
        var addrInput = get('wcof_delivery_address');
        if(!townInput || !addrInput) return;
        var errorEl = document.getElementById('wcof-delivery-error');
        var dragLink = document.getElementById('wcof-move-marker');
        var mapEl = document.getElementById('wcof-delivery-map');
        var resolvedInput = get('wcof_delivery_resolved');
        var coordInput = get('wcof_delivery_coords');
        var validInput = get('wcof_delivery_valid');
        var summaryEl = document.getElementById('wcof-resolved-display');
        var addressSelect = document.getElementById('wcof-address-select');
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

        // Default view. Adjust later if postal codes provide a region.
        map.setView([0, 0], 2);
        if(allowed.length){
            fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&postalcode='+encodeURIComponent(allowed[0]))
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if(Array.isArray(d) && d[0]){
                        var item = d[0];
                        if(item.boundingbox){
                            var bb = item.boundingbox.map(parseFloat);
                            map.fitBounds([[bb[0], bb[2]], [bb[1], bb[3]]]);
                        }else if(item.lat && item.lon){
                            map.setView([parseFloat(item.lat), parseFloat(item.lon)], 12);
                        }
                    }
                }).catch(function(){});
        }

        function reverseAndFill(latlng){
            if(coordInput) coordInput.value = latlng.lat + ',' + latlng.lng;
            fetch('https://nominatim.openstreetmap.org/reverse?format=json&addressdetails=1&lat='+latlng.lat+'&lon='+latlng.lng)
                .then(function(r){return r.json();})
                .then(function(data){
                    var addr = data.address || {};
                    var pc = addr.postcode || '';
                    if(allowed.length && allowed.indexOf(pc) === -1){
                        showError('Address not in delivery area or not found');
                        if(validInput) validInput.value='';
                        if(resolvedInput) resolvedInput.value='';
                        if(summaryEl){ summaryEl.textContent=''; summaryEl.style.display='none'; }
                        document.querySelector('#billing_state').value = '';
                        document.querySelector('#shipping_state').value = '';
                        toggleQuickPayButtons(false);
                        return;
                    }
                    hideError();
                    var full = data.display_name || '';
                    if(resolvedInput) resolvedInput.value = full;
                    if(summaryEl){
                        summaryEl.textContent = full + ' (' + latlng.lat + ',' + latlng.lng + ')';
                        summaryEl.style.display = 'block';
                    }
                    document.querySelector('#billing_postcode').value = pc;
                    document.querySelector('#billing_address_1').value = full;
                    document.querySelector('#billing_city').value = addr.city || addr.town || addr.village || '';
                    document.querySelector('#billing_country').value = (addr.country_code || '').toUpperCase();
                    document.querySelector('#billing_state').value = '';
                    document.querySelector('#shipping_state').value = '';
                    lastValid = latlng;
                    if(validInput) validInput.value='1';
                    toggleQuickPayButtons(true);
                });
        }

        function placeMarker(lat, lon){
            var latlng = Leaflet.latLng(lat, lon);
            if(!marker){
                marker = Leaflet.marker(latlng, {draggable:false}).addTo(map);
                marker.on('dragend', function(e){
                    reverseAndFill(e.target.getLatLng());
                    marker.dragging.disable();
                    editing = false;
                });
            }else{
                marker.setLatLng(latlng);
            }
            map.setView(latlng, 16);
            reverseAndFill(latlng);
        }

        function searchAddress(){
            var town = townInput.value.trim();
            var addr = addrInput.value.trim();
            if(town.length < 2 || addr.length < 3) return;
            // Nominatim performs better when the town is part of the search
            // string separated by a space rather than a comma. Combine the two
            // inputs accordingly so the geocoder receives a single query.
            var q = addr + ' ' + town;
            return fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=1&q='+encodeURIComponent(q))
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

        function resetState(){
            if(validInput) validInput.value='';
            if(resolvedInput) resolvedInput.value='';
            if(summaryEl){ summaryEl.textContent=''; summaryEl.style.display='none'; }
            hideError();
            toggleQuickPayButtons(false);
        }
        [townInput, addrInput].forEach(function(el){
            el.addEventListener('input', resetState);
            el.addEventListener('change', function(){
                if(townInput.value && addrInput.value){ searchAddress(); }
            });
            el.addEventListener('keydown', function(e){
                if(e.key === 'Enter'){
                    e.preventDefault();
                    if(townInput.value && addrInput.value){ searchAddress(); }
                }
            });
        });

        var valueObserver = new MutationObserver(function(){
            if(townInput.value && addrInput.value){
                var result = searchAddress();
                if(result && typeof result.then === 'function'){
                    result.then(function(){ valueObserver.disconnect(); });
                }else{
                    valueObserver.disconnect();
                }
            }
        });
        valueObserver.observe(townInput, {attributes:true, attributeFilter:['value']});
        valueObserver.observe(addrInput, {attributes:true, attributeFilter:['value']});

        map.on('click', function(e){
            if(editing){
                placeMarker(e.latlng.lat, e.latlng.lng);
                if(marker){
                    marker.dragging.disable();
                }
                editing = false;
            }
        });

        if(dragLink){
            dragLink.addEventListener('click', function(e){
                e.preventDefault();
                editing = true;
                if(validInput) validInput.value='';
                if(resolvedInput) resolvedInput.value='';
                if(summaryEl){ summaryEl.textContent=''; summaryEl.style.display='none'; }
                if(marker) marker.dragging.enable();
                toggleQuickPayButtons(false);
            });
        }

        if(addressSelect){
            addressSelect.addEventListener('change', function(){
                var opt = addressSelect.options[addressSelect.selectedIndex];
                if(!opt) return;
                townInput.value = opt.getAttribute('data-town') || '';
                addrInput.value = opt.getAttribute('data-address') || '';
                if(resolvedInput) resolvedInput.value = opt.getAttribute('data-resolved') || '';
                if(coordInput) coordInput.value = opt.getAttribute('data-coords') || '';
                var r = opt.getAttribute('data-resolved') || '';
                var c = opt.getAttribute('data-coords') || '';
                if(summaryEl){
                    if(r && c){ summaryEl.textContent = r + ' (' + c + ')'; summaryEl.style.display='block'; }
                    else { summaryEl.textContent=''; summaryEl.style.display='none'; }
                }
                if(c){
                    var parts = c.split(',');
                    if(parts.length === 2){ placeMarker(parts[0], parts[1]); }
                }
            });
        }

        if(coordInput && coordInput.value){
            var parts = coordInput.value.split(',');
            if(parts.length === 2){
                placeMarker(parts[0], parts[1]);
                if(resolvedInput && resolvedInput.value && summaryEl){
                    summaryEl.textContent = resolvedInput.value + ' (' + coordInput.value + ')';
                    summaryEl.style.display = 'block';
                }
            }
        }

        var heading=document.querySelector('.woocommerce-billing-fields > h3');
        if(heading) heading.style.display='none';
        var ship=document.querySelector('.woocommerce-shipping-fields');
        if(ship) ship.style.display='none';
    }

    // Wait for the DOM to be fully loaded before initializing.
    document.addEventListener('DOMContentLoaded', init);
})();
