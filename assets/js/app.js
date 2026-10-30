document.addEventListener('DOMContentLoaded', function () {
    const mapElement = document.getElementById('map');
    if (!mapElement) return;

    const apiBase = window.location.pathname.includes('/admin/') ? '../api/' : 'api/';
    const map = L.map('map').setView([3.1390, 101.6869], 7);
    const markerLayer = L.layerGroup().addTo(map);
    const routeLayer = L.layerGroup().addTo(map);
    const summaryElement = document.getElementById('tracking-summary');
    const riderIcon = L.divIcon({
        className: 'bootstrap-map-marker',
        html: '<span class="map-marker-icon map-marker-rider"><i class="bi bi-scooter"></i></span>',
        iconSize: [42, 42],
        iconAnchor: [21, 21],
        popupAnchor: [0, -23]
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[ch]));

    const renderSummary = (cards) => {
        if (!summaryElement) return;
        summaryElement.innerHTML = cards.length
            ? cards.join('')
            : '<div class="tracking-card"><h3>No active online rider</h3><p class="muted">Ask a rider to open the Rider Dashboard, allow GPS access, and go online.</p></div>';
    };

    const refreshTracking = async () => {
        try {
            const response = await fetch(apiBase + 'riders.php', { cache: 'no-store' });
            if (!response.ok) throw new Error('Could not load rider locations.');

            const riders = await response.json();
            markerLayer.clearLayers();
            const bounds = [];
            const cards = [];
            const renderedPositionCounts = new Map();

            riders.forEach((rider) => {
                const hasGps = rider.current_latitude !== null && rider.current_longitude !== null;
                if (!hasGps) {
                    cards.push(`<div class="tracking-card"><h3>${escapeHtml(rider.full_name)}</h3><p>Online, but GPS location has not been received yet.</p><p class="muted">Ask the rider to keep the Rider Dashboard open and allow location access.</p></div>`);
                    return;
                }

                const lat = Number(rider.current_latitude);
                const lng = Number(rider.current_longitude);
                const positionKey = `${lat.toFixed(6)},${lng.toFixed(6)}`;
                const matchingRiders = renderedPositionCounts.get(positionKey) || 0;
                renderedPositionCounts.set(positionKey, matchingRiders + 1);

                // Two riders can report the exact same GPS point (for example
                // when both accounts are tested on one device). Fan subsequent
                // pins out slightly so every online rider remains visible.
                const displayLat = lat + (matchingRiders * 0.00035);
                const displayLng = lng + (matchingRiders * 0.00035);
                L.marker([displayLat, displayLng], { icon: riderIcon })
                    .bindPopup(`<strong>${escapeHtml(rider.full_name)}</strong><br>Rider GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}<br>Status: ${escapeHtml(rider.status)}`)
                    .addTo(markerLayer);
                bounds.push([lat, lng]);
                cards.push(`<div class="tracking-card"><h3>${escapeHtml(rider.full_name)}</h3><p>GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}</p><p>Status: ${escapeHtml(rider.status)}</p></div>`);
            });
            // Display delivery routes for online riders
routeLayer.clearLayers();

for (const rider of riders) {
    const riderId = Number(rider.id);
    const parcelId = Number(rider.active_parcel_id);

    if (!riderId || !parcelId) continue;

    try {
        const params = new URLSearchParams({
            rider_id: riderId,
            parcel_id: parcelId
        });

        const response = await fetch(
            apiBase + 'route.php?' + params.toString(),
            { cache: 'no-store' }
        );

        const route = await response.json();

        if (!response.ok || !route.success) {
            console.warn('Route unavailable:', route.message);
            continue;
        }

        // Draw blue delivery route
        const coordinates = route.geometry?.coordinates || [];

        if (coordinates.length > 1) {
            const points = coordinates.map(
                ([lng, lat]) => [lat, lng]
            );

            L.polyline(points, {
                color: '#2563eb',
                weight: 5,
                opacity: 0.85
            }).addTo(routeLayer);
        }

        // Show customer delivery location
if (route.end) {
    L.marker([route.end.lat, route.end.lng])
        .bindPopup('📦 Customer Delivery Location')
        .addTo(routeLayer);

    // Include customer location in automatic map zoom
    bounds.push([route.end.lat, route.end.lng]);
}

// Include all delivery route points in automatic map zoom
if (coordinates.length > 1) {
    coordinates.forEach(([lng, lat]) => {
        bounds.push([lat, lng]);
    });
}
    } catch (error) {
        console.error('Could not load delivery route:', error);
    }
}

            renderSummary(cards);
            if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
        } catch (error) {
            renderSummary([`<div class="tracking-card"><h3>Tracking unavailable</h3><p>${escapeHtml(error.message || 'Could not load rider tracking data.')}</p></div>`]);
        }
    };

    refreshTracking();
    setInterval(refreshTracking, 5000);
});
