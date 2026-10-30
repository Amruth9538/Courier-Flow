document.addEventListener('DOMContentLoaded', function () {
    const mapElement = document.getElementById('rider-map');
    if (!mapElement) return;

    const summaryElement = document.getElementById('rider-tracking-summary');
    const riderId = Number(document.body.dataset.riderId || 0);
    const parcelId = Number(document.body.dataset.parcelId || 0);
    const isOnline = document.body.dataset.riderOnline === 'true';
    const map = L.map('rider-map').setView([3.1390, 101.6869], 7);
    const routeLayer = L.layerGroup().addTo(map);
    const markerLayer = L.layerGroup().addTo(map);
    const bootstrapMapIcon = (variant, icon) => L.divIcon({
        className: 'bootstrap-map-marker',
        html: `<span class="map-marker-icon map-marker-${variant}"><i class="bi ${icon}"></i></span>`,
        iconSize: [42, 42],
        iconAnchor: [21, 21],
        popupAnchor: [0, -23]
    });
    const riderIcon = bootstrapMapIcon('rider', 'bi-scooter');
    const recipientIcon = bootstrapMapIcon('destination', 'bi-geo-alt-fill');
    let riderMarker = null;
    let destinationMarker = null;
    let lastRouteRequest = 0;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const setSummary = (html) => {
        if (summaryElement) summaryElement.innerHTML = html;
    };

    if (!isOnline) {
        setSummary('<div class="tracking-card"><h3>Location sharing is off</h3><p>Go online to show your live location, delivery destination, and driving route.</p></div>');
        return;
    }

    const showRoute = async () => {
        if (!parcelId) {
            setSummary('<div class="tracking-card"><h3>Current location</h3><p>Your location is shown on the map. No active delivery is assigned yet.</p></div>');
            return;
        }

        const now = Date.now();
        if (now - lastRouteRequest < 15000) return;
        lastRouteRequest = now;

        try {
            const params = new URLSearchParams({
                rider_id: riderId,
                parcel_id: parcelId,
                // Publish the route calculated from the latest saved GPS
                // position. The admin dashboard reads this same snapshot.
                refresh: '1'
            });
            const response = await fetch('api/route.php?' + params.toString(), { cache: 'no-store' });
            const route = await response.json();
            if (!route.success) throw new Error(route.message || 'Could not calculate the delivery route.');

            routeLayer.clearLayers();
            const coordinates = route.geometry?.coordinates || [];
            const latLngs = coordinates.map(coord => [coord[1], coord[0]]);
            if (latLngs.length > 1) {
                L.polyline(latLngs, { color: '#2563eb', weight: 5, opacity: 0.85 }).addTo(routeLayer);
            }
            if (route.end) {
                if (destinationMarker) markerLayer.removeLayer(destinationMarker);
                destinationMarker = L.marker([route.end.lat, route.end.lng])
                    .setIcon(recipientIcon)
                    .bindPopup('Recipient delivery address')
                    .addTo(markerLayer);
            }
            const routeStart = route.start ? [route.start.lat, route.start.lng] : null;
            if (routeStart) {
                if (riderMarker) {
                    riderMarker.setLatLng(routeStart);
                } else {
                    riderMarker = L.marker(routeStart, { icon: riderIcon })
                        .bindPopup('Your current location')
                        .addTo(markerLayer);
                }
            }
            const bounds = routeStart ? L.latLngBounds([routeStart]) : L.latLngBounds();
            latLngs.forEach(point => bounds.extend(point));
            if (bounds.isValid()) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });

            setSummary(`<div class="tracking-card"><h3>Active delivery route</h3><p>Distance to recipient: <span class="tracking-metric">${route.distance_km ?? '—'} km</span></p><p>Estimated driving time: <span class="tracking-metric">${route.duration_min ?? '—'} min</span></p></div>`);
        } catch (error) {
            setSummary(`<div class="tracking-card"><h3>Route unavailable</h3><p>${error.message || 'Could not calculate the delivery route.'}</p></div>`);
        }
    };

    const updatePosition = async (position) => {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        const point = [lat, lng];

        if (riderMarker) {
            riderMarker.setLatLng(point);
        } else {
            riderMarker = L.marker(point, { icon: riderIcon })
                .bindPopup('Your current location')
                .addTo(markerLayer);
        }
        map.setView(point, 15);

        try {
            const response = await fetch('api/location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lat, lng })
            });
            if (!response.ok) throw new Error('Could not save your current location.');
            await showRoute();
        } catch (error) {
            setSummary(`<div class="tracking-card"><h3>Location update unavailable</h3><p>${error.message}</p></div>`);
        }
    };

    if (!navigator.geolocation) {
        setSummary('<div class="tracking-card"><h3>Location unavailable</h3><p>Your browser does not support location services.</p></div>');
        return;
    }

    setSummary('<div class="tracking-card"><h3>Getting your location…</h3><p>Please allow location access when your browser asks.</p></div>');
    // Show the saved rider position, destination and route immediately. Live
    // browser GPS then keeps the displayed route current.
    showRoute();
    navigator.geolocation.watchPosition(
        updatePosition,
        () => setSummary('<div class="tracking-card"><h3>Location permission needed</h3><p>Allow location access, then refresh this page to show your route.</p></div>'),
        { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 }
    );
});
