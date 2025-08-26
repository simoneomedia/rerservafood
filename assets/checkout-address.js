(function(){
    if(typeof wcofCheckoutAddress === 'undefined') return;
    document.addEventListener('DOMContentLoaded', function(){
        var allowed = wcofCheckoutAddress.postalCodes || [];
        var input = document.querySelector('#wcof_delivery_address');
        if(!input) return;
        var datalist = document.createElement('datalist');
        datalist.id = 'wcof-address-list';
        document.body.appendChild(datalist);
        input.setAttribute('list', datalist.id);
        var map = L.map('wcof-delivery-map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        var marker = null;
        var lastValid = null;
        var suggestions = [];

        function fitBoundsForPostalCodes(codes){
            if(!codes.length) return;
            var requests = codes.map(function(pc){
                return fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&postalcode='+encodeURIComponent(pc));
            });
            Promise.all(requests).then(function(res){
                return Promise.all(res.map(function(r){ return r.json(); }));
            }).then(function(arr){
                var bounds = null;
                arr.forEach(function(res){
                    if(res[0] && res[0].boundingbox){
                        var b = res[0].boundingbox;
                        var bb = [[b[0], b[2]], [b[1], b[3]]];
                        bounds = bounds ? bounds.extend(bb) : L.latLngBounds(bb);
                    }
                });
                if(bounds){
                    map.fitBounds(bounds);
                    map.setMaxBounds(bounds);
                }
            });
        }

        fitBoundsForPostalCodes(allowed);

        function reverseAndFill(latlng){
            fetch('https://nominatim.openstreetmap.org/reverse?format=json&addressdetails=1&lat='+latlng.lat+'&lon='+latlng.lng)
                .then(function(r){return r.json();})
                .then(function(data){
                    var addr = data.address || {};
                    var pc = addr.postcode || '';
                    if(allowed.length && allowed.indexOf(pc) === -1){
                        alert('Indirizzo fuori zona consegna');
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
            var latlng = L.latLng(lat, lon);
            if(!marker){
                marker = L.marker(latlng, {draggable:true}).addTo(map);
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
                        if(allowed.length && allowed.indexOf(item.address.postcode) === -1) return;
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
    });
})();
