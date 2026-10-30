document.addEventListener('DOMContentLoaded', async function () {
    const mapElement = document.getElementById('route-history-map');

    if (!mapElement || typeof L === 'undefined') return;

    const tracking = mapElement.dataset.tracking;
    const apiBase = mapElement.dataset.apiBase || 'api/';
    const message = document.getElementById('route-history-message');

    const setMessage = (text) => {
        if (message) message.textContent = text;
    };

    // Map marker icons
    const bootstrapMapIcon = (variant, icon) => L.divIcon({
        className: 'bootstrap-map-marker',
        html: `<span class="map-marker-icon map-marker-${variant}"><i class="bi ${icon}"></i></span>`,
        iconSize: [42, 42],
        iconAnchor: [21, 21],
        popupAnchor: [0, -23]
    });

    const riderIcon = bootstrapMapIcon('rider', 'bi-scooter');
    const destinationIcon = bootstrapMapIcon(
        'destination',
        'bi-geo-alt-fill'
    );

    // Create map
    const map = L.map(mapElement).setView([3.1390, 101.6869], 7);

    L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }
    ).addTo(map);

    // Separate map layers
    const plannedRouteLayer = L.layerGroup().addTo(map);
    const actualRouteLayer = L.layerGroup().addTo(map);
    const destinationLayer = L.layerGroup().addTo(map);

    // Only one live rider marker
    let liveRiderMarker = null;

    // Track latest processed rider update
    let lastRiderUpdate = null;

    // Prevent overlapping refresh requests
    let refreshInProgress = false;
        // Display distance remaining and estimated delivery time
    function updateDeliveryETA(data) {
        const distanceElement = document.getElementById('distance-remaining');
        const timeElement = document.getElementById('estimated-delivery-time');
            // Show completed status for delivered parcels
    if (String(data.status || '').toLowerCase() === 'delivered') {
        if (distanceElement) {
            distanceElement.textContent = '0 km';
        }

        if (timeElement) {
            timeElement.textContent = 'Delivery Completed';
        }

        return;
    }

        if (distanceElement) {
            if (data.distance_km !== null && data.distance_km !== undefined) {
                distanceElement.textContent =
                    Number(data.distance_km).toFixed(2) + ' km';
            } else {
                distanceElement.textContent = 'Not available';
            }
        }

        if (timeElement) {
            if (data.duration_min !== null && data.duration_min !== undefined) {
                timeElement.textContent =
                    Math.ceil(Number(data.duration_min)) + ' minutes';
            } else {
                timeElement.textContent = 'Not available';
            }
        }
    }

    // Update live rider marker
    function updateLiveRider(data) {
        if (!data.latest_rider_position) {
            if (liveRiderMarker) {
                map.removeLayer(liveRiderMarker);
                liveRiderMarker = null;
            }

            lastRiderUpdate = null;
            return;
        }

        const lat = Number(data.latest_rider_position.lat);
        const lng = Number(data.latest_rider_position.lng);

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }

        const position = [lat, lng];

        const updatedAt =
            data.latest_rider_position.last_seen || 'Unknown';

        // Update marker position without creating duplicates
        if (liveRiderMarker) {
            liveRiderMarker.setLatLng(position);
        } else {
            liveRiderMarker = L.marker(position, {
                icon: riderIcon,
                zIndexOffset: 1200
            }).addTo(map);
        }

        liveRiderMarker.bindPopup(
            `Current Rider Location<br>Updated: ${updatedAt}`
        );

        lastRiderUpdate = updatedAt;
    }

    // Load planned route, travelled route and destination
    function displayRouteHistory(data) {
        const bounds = L.latLngBounds();

        plannedRouteLayer.clearLayers();
        actualRouteLayer.clearLayers();
        destinationLayer.clearLayers();

        // Blue dashed planned route
        const planCoordinates =
            data.plan?.geometry?.coordinates || [];

        if (planCoordinates.length > 1) {
            const planPoints = planCoordinates.map(
                ([lng, lat]) => [lat, lng]
            );

            L.polyline(planPoints, {
                color: '#2563eb',
                weight: 5,
                dashArray: '10 8',
                opacity: 0.85
            })
                .bindPopup('Planned Delivery Route')
                .addTo(plannedRouteLayer);

            planPoints.forEach(point => bounds.extend(point));
        }

        // Orange actual travelled route
        const actualPoints = (data.actual_points || [])
            .map(point => [
                Number(point.latitude),
                Number(point.longitude)
            ])
            .filter(point =>
                Number.isFinite(point[0]) &&
                Number.isFinite(point[1])
            );

        if (actualPoints.length > 1) {
            L.polyline(actualPoints, {
                color: '#f97316',
                weight: 5,
                opacity: 0.9
            })
                .bindPopup('Actual Rider Route')
                .addTo(actualRouteLayer);

            actualPoints.forEach(point => bounds.extend(point));

            // Recorded GPS points
            actualPoints.slice(0, -1).forEach(point => {
                L.circleMarker(point, {
                    radius: 4,
                    color: '#2563eb',
                    weight: 2,
                    fillColor: '#fff',
                    fillOpacity: 1
                })
                    .bindPopup('Recorded Rider GPS Point')
                    .addTo(actualRouteLayer);
            });

            // Starting location
            L.marker(actualPoints[0], {
                icon: riderIcon,
                zIndexOffset: 900
            })
                .bindPopup('Actual Route Start')
                .addTo(actualRouteLayer);
        } else if (actualPoints.length === 1) {
            bounds.extend(actualPoints[0]);
        }

        // Customer delivery destination
        if (data.destination) {
            const destination = [
                Number(data.destination.lat),
                Number(data.destination.lng)
            ];

            if (
                Number.isFinite(destination[0]) &&
                Number.isFinite(destination[1])
            ) {
                L.marker(destination, {
                    icon: destinationIcon
                })
                    .bindPopup('Customer Delivery Location')
                    .addTo(destinationLayer);

                bounds.extend(destination);
            }
        }

        // Include rider in initial map bounds
        if (data.latest_rider_position) {
            const lat = Number(data.latest_rider_position.lat);
            const lng = Number(data.latest_rider_position.lng);

            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                bounds.extend([lat, lng]);
            }
        }

        // Fit map only during initial loading
        if (bounds.isValid()) {
            map.fitBounds(bounds, {
                padding: [35, 35],
                maxZoom: 15
            });
        }

        if (!data.plan && actualPoints.length < 2) {
            setMessage(
                'No planned route or sufficient GPS history is available yet.'
            );
        } else {
            setMessage(
                'Blue dashed: planned route. ' +
                'Orange: actual rider route. ' +
                'Blue dots: recorded GPS points. ' +
                'Blue scooter: latest rider location.'
            );
        }
    }

    // Get tracking data from PHP API
    async function fetchTrackingData() {
        const response = await fetch(
            `${apiBase}route-history.php?tracking=${encodeURIComponent(tracking)}`,
            { cache: 'no-store' }
        );

        if (!response.ok) {
            throw new Error('Could not load tracking information.');
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error(
                data.message || 'Could not load route history.'
            );
        }

        return data;
    }

    // Initial page loading
    try {
        // Preserve existing route-planning behaviour
        if (mapElement.dataset.planUrl) {
            const planResponse = await fetch(
                mapElement.dataset.planUrl,
                { cache: 'no-store' }
            );

            const planResult = await planResponse.json();

            if (!planResponse.ok || !planResult.success) {
                throw new Error(
                    planResult.message ||
                    'Could not create the planned route.'
                );
            }
        }

        const data = await fetchTrackingData();

        displayRouteHistory(data);
        updateLiveRider(data);
        updateDeliveryETA(data);

    } catch (error) {
        setMessage(
            error.message || 'Could not load route history.'
        );
    }

    // Automatically refresh rider location every 5 seconds
    async function refreshLiveRider() {
        if (refreshInProgress) return;

        refreshInProgress = true;

        try {
            const data = await fetchTrackingData();

            updateLiveRider(data);
            updateDeliveryETA(data);

        } catch (error) {
            console.error(
                'Live rider tracking error:',
                error
            );
        } finally {
            refreshInProgress = false;
        }
    }

    setInterval(refreshLiveRider, 5000);
});