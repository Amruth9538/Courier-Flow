document.addEventListener('DOMContentLoaded', function () {
    const mapElement = document.getElementById('rider-map');
    if (!mapElement || typeof L === 'undefined') return;

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
    let currentPosition = null;
    let routeRequestAt = 0;
    let routeRequestPosition = null;
    let routeRequestInProgress = false;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const setSummary = (title, messages = []) => {
        if (!summaryElement) return;

        const card = document.createElement('div');
        card.className = 'tracking-card';

        const heading = document.createElement('h3');
        heading.textContent = title;
        card.appendChild(heading);

        messages.forEach((message) => {
            const paragraph = document.createElement('p');
            paragraph.textContent = message;
            card.appendChild(paragraph);
        });

        summaryElement.replaceChildren(card);
    };

    const locationControl = L.control({ position: 'topright' });
    locationControl.onAdd = function () {
        const container = L.DomUtil.create('div', 'leaflet-bar rider-location-control');
        const button = L.DomUtil.create('button', 'rider-location-button', container);
        const icon = document.createElement('i');
        const label = document.createElement('span');

        button.type = 'button';
        button.title = 'Show my current location';
        button.setAttribute('aria-label', 'Show my current location');
        icon.className = 'bi bi-crosshair';
        icon.setAttribute('aria-hidden', 'true');
        label.textContent = 'My location';
        button.append(icon, label);

        L.DomEvent.disableClickPropagation(container);
        L.DomEvent.disableScrollPropagation(container);
        L.DomEvent.on(button, 'click', function () {
            if (currentPosition) {
                map.setView(currentPosition, 16);
                if (riderMarker) riderMarker.openPopup();
                return;
            }

            if (!navigator.geolocation) {
                setSummary('Location unavailable', ['Your browser does not support location services.']);
                return;
            }

            setSummary('Finding your location', ['Please allow location access when your browser asks.']);
            navigator.geolocation.getCurrentPosition(
                (location) => {
                    if (isOnline) {
                        updatePosition(location);
                        return;
                    }

                    currentPosition = [location.coords.latitude, location.coords.longitude];
                    riderMarker = L.marker(currentPosition, { icon: riderIcon })
                        .bindPopup('Your current location')
                        .addTo(markerLayer);
                    map.setView(currentPosition, 16);
                    riderMarker.openPopup();
                    setSummary('Current location', ['Your current position is shown on the map.']);
                },
                () => setSummary('Location permission needed', [
                    'Allow location access in your browser, then try again.'
                ]),
                { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 }
            );
        });

        return container;
    };
    locationControl.addTo(map);

    const distanceBetween = (first, second) => {
        const radians = degrees => degrees * Math.PI / 180;
        const latDistance = radians(second[0] - first[0]);
        const lngDistance = radians(second[1] - first[1]);
        const value = Math.sin(latDistance / 2) ** 2
            + Math.cos(radians(first[0])) * Math.cos(radians(second[0]))
            * Math.sin(lngDistance / 2) ** 2;

        return 6371000 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
    };

    const renderRoute = (route) => {
        const coordinates = route.geometry?.coordinates;
        if (!Array.isArray(coordinates) || coordinates.length < 2) {
            throw new Error('The routing service returned no road route.');
        }

        const points = coordinates.map(([lng, lat]) => [Number(lat), Number(lng)]);
        if (points.some(([lat, lng]) => !Number.isFinite(lat) || !Number.isFinite(lng))) {
            throw new Error('The routing service returned invalid route coordinates.');
        }

        routeLayer.clearLayers();
        L.polyline(points, {
            color: '#2563eb',
            weight: 5,
            opacity: 0.88
        }).bindPopup('Road route to delivery').addTo(routeLayer);

        if (route.end) {
            L.marker([Number(route.end.lat), Number(route.end.lng)], {
                icon: recipientIcon
            }).bindPopup('Delivery destination').addTo(routeLayer);
        }

        const routeBounds = L.latLngBounds(points);
        map.fitBounds(routeBounds, { padding: [36, 36], maxZoom: 14 });

        const details = [];
        if (route.distance_km !== null && route.distance_km !== undefined) {
            details.push(`${Number(route.distance_km).toFixed(1)} km remaining`);
        }
        if (route.duration_min !== null && route.duration_min !== undefined) {
            details.push(`About ${Math.ceil(Number(route.duration_min))} min`);
        }
        if (route.provider && route.provider !== 'OpenRouteService') {
            details.push(`Directions via ${route.provider}`);
        }
        details.push('Route refreshes as your location changes.');

        setSummary(
            route.tracking_number
                ? `Route to ${route.tracking_number}`
                : 'Live delivery route',
            details
        );
    };

    const loadRoute = async (position) => {
        if (!parcelId || routeRequestInProgress) return;

        const now = Date.now();
        const movedMeters = routeRequestPosition
            ? distanceBetween(routeRequestPosition, position)
            : Infinity;
        if (now - routeRequestAt < 60000 && movedMeters < 250) return;

        routeRequestAt = now;
        routeRequestPosition = position;
        routeRequestInProgress = true;

        try {
            const params = new URLSearchParams({
                rider_id: String(riderId),
                parcel_id: String(parcelId),
                refresh: '1'
            });
            const response = await fetch(`api/route.php?${params.toString()}`, {
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            const route = await response.json();

            if (!response.ok || !route.success) {
                throw new Error(route.message || 'Could not calculate the delivery route.');
            }

            renderRoute(route);
        } catch (error) {
            setSummary('Road route unavailable', [
                error.message || 'Could not calculate the delivery route.',
                'Check the routing service configuration and try again.'
            ]);
        } finally {
            routeRequestInProgress = false;
        }
    };

    if (!isOnline) {
        setSummary('Location sharing is off', [
            'Go online to show your live location, delivery destination, and driving route.'
        ]);
        return;
    }

    if (!parcelId) {
        setSummary('Current location', [
            'Your live location will appear on the map.',
            'No active delivery is assigned yet.'
        ]);
    } else {
        setSummary('Finding your delivery route', [
            'Your road route and estimated travel time will appear when your location is available.'
        ]);
    }

    const updatePosition = async (location) => {
        const lat = location.coords.latitude;
        const lng = location.coords.longitude;
        const position = [lat, lng];

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            setSummary('Location unavailable', ['Your browser returned invalid GPS coordinates.']);
            return;
        }

        currentPosition = position;

        if (riderMarker) {
            riderMarker.setLatLng(position);
        } else {
            riderMarker = L.marker(position, { icon: riderIcon })
                .bindPopup('Your current location')
                .addTo(markerLayer);
            map.setView(position, 15);
        }

        try {
            const response = await fetch('api/location.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json'
                },
                body: JSON.stringify({ lat, lng })
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not save your current location.');
            }

            if (parcelId) {
                await loadRoute(position);
            }
        } catch (error) {
            setSummary('Location update unavailable', [
                error.message || 'Could not update your location. Please try again.'
            ]);
        }
    };

    if (!navigator.geolocation) {
        setSummary('Location unavailable', ['Your browser does not support location services.']);
        return;
    }

    navigator.geolocation.watchPosition(
        updatePosition,
        () => setSummary('Location permission needed', [
            'Allow location access, then refresh this page to show your route.'
        ]),
        { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 }
    );
});
