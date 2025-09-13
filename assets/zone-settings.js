(function(){
    if(typeof wcofZoneSettings === 'undefined') return;
    var mapEl = document.getElementById('wcof_zone_map');
    if(!mapEl) return;
    var Leaflet = window.L;
    var map = Leaflet.map(mapEl).setView([0,0],2);
    Leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    var drawn = null;
    var polyInput = document.getElementById('wcof_delivery_polygon');
    var group = Leaflet.featureGroup().addTo(map);
    if(wcofZoneSettings.polygon){
        try{
            var coords = JSON.parse(wcofZoneSettings.polygon);
            drawn = Leaflet.polygon(coords).addTo(group);
            map.fitBounds(drawn.getBounds());
        }catch(e){}
    }else if(wcofZoneSettings.address){
        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q='+encodeURIComponent(wcofZoneSettings.address))
            .then(function(r){return r.json();})
            .then(function(d){ if(Array.isArray(d) && d[0]) map.setView([parseFloat(d[0].lat), parseFloat(d[0].lon)], 12); })
            .catch(function(){});
    }
    var draw = new Leaflet.Control.Draw({
        draw:{
            marker:false,polyline:false,rectangle:false,circle:false,circlemarker:false,
            polygon:true
        },
        edit:{ featureGroup: group }
    });
    map.addControl(draw);
    function updateInput(){
        if(drawn){
            var ll = drawn.getLatLngs()[0].map(function(p){ return [p.lat, p.lng]; });
            polyInput.value = JSON.stringify(ll);
        }
    }
    map.on(Leaflet.Draw.Event.CREATED, function(e){
        group.clearLayers();
        drawn = e.layer;
        group.addLayer(drawn);
        updateInput();
    });
    map.on(Leaflet.Draw.Event.EDITED, updateInput);
    map.on(Leaflet.Draw.Event.DELETED, function(){
        drawn = null;
        polyInput.value = '';
    });
})();
