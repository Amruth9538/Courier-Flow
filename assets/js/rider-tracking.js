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
        setSummary(`
            <div class="tracking-card">
                <h3>Current Location</h3>
                <p>Your live location will appear on the map.</p>
                <p>No active delivery is assigned yet.</p>
            </div>
        `);
    } else {
        setSummary(`
            <div class="tracking-card">
                <h3>Live Delivery Tracking</h3>
                <p>Your current GPS location is displayed on the map.</p>
                <p>Road route calculation is disabled.</p>
            </div>
        `);
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
