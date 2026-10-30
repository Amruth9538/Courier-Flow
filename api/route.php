<?php
require_once __DIR__ . '/../config.php';
require_auth();

if (!is_admin() && !is_rider()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

$riderId = (int) ($_GET['rider_id'] ?? 0);
$parcelId = (int) ($_GET['parcel_id'] ?? 0);
$startLat = isset($_GET['start_lat']) && $_GET['start_lat'] !== '' ? (float) $_GET['start_lat'] : null;
$startLng = isset($_GET['start_lng']) && $_GET['start_lng'] !== '' ? (float) $_GET['start_lng'] : null;
$endLat = isset($_GET['end_lat']) && $_GET['end_lat'] !== '' ? (float) $_GET['end_lat'] : null;
$endLng = isset($_GET['end_lng']) && $_GET['end_lng'] !== '' ? (float) $_GET['end_lng'] : null;
$usedStoredRecipientCoordinates = false;
$refreshRoute = !empty($_GET['refresh']) && is_rider();

if ($riderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid rider ID']);
    exit;
}

if (is_rider()) {
    $currentRider = ensure_rider_profile();
    if (!$currentRider || (int) $currentRider['id'] !== $riderId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only view your own delivery route.']);
        exit;
    }
}

if ($startLat === null || $startLng === null) {
    $stmt = $pdo->prepare("SELECT r.current_latitude, r.current_longitude FROM riders r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND u.role = 'rider'");
    $stmt->execute([$riderId]);
    $rider = $stmt->fetch();

    if (!$rider || $rider['current_latitude'] === null || $rider['current_longitude'] === null) {
        echo json_encode(['success' => false, 'message' => 'Rider has no current Malaysia GPS location yet. Ask the rider to open the Rider Dashboard and allow location access.']);
        exit;
    }

    $startLat = (float) $rider['current_latitude'];
    $startLng = (float) $rider['current_longitude'];
}

if ($parcelId > 0 && ($endLat === null || $endLng === null)) {
    $stmt = $pdo->prepare('SELECT tracking_number, recipient_address, recipient_latitude, recipient_longitude, assigned_rider_id FROM parcels WHERE id = ?');
    $stmt->execute([$parcelId]);
    $parcel = $stmt->fetch();

    if (!$parcel) {
        echo json_encode(['success' => false, 'message' => 'Parcel not found.']);
        exit;
    }

    if ((int) $parcel['assigned_rider_id'] !== $riderId) {
        echo json_encode(['success' => false, 'message' => 'This parcel is not assigned to the selected rider.']);
        exit;
    }

    if ($parcel['recipient_latitude'] !== null && $parcel['recipient_longitude'] !== null) {
        $endLat = (float) $parcel['recipient_latitude'];
        $endLng = (float) $parcel['recipient_longitude'];
        $usedStoredRecipientCoordinates = true;
    } else {
        $coordinates = geocode_malaysia_address($parcel['recipient_address']);
        if (!$coordinates) {
            echo json_encode(['success' => false, 'message' => 'Could not find this Malaysian delivery address. Please include city/state/postcode in the recipient address.']);
            exit;
        }
        $endLat = $coordinates['lat'];
        $endLng = $coordinates['lng'];

        $pdo->prepare('UPDATE parcels SET recipient_latitude = ?, recipient_longitude = ?, recipient_geocoded_at = NOW() WHERE id = ?')
            ->execute([$endLat, $endLng, $parcelId]);
    }
}

if ($endLat === null || $endLng === null) {
    echo json_encode(['success' => false, 'message' => 'Delivery destination is missing. Select a parcel or provide destination coordinates.']);
    exit;
}

// A plan is created only by an administrator. If live tracking existed before
// the admin opens the route, calculate once to establish the A-to-C baseline.
$needsAdminPlan = false;
if ($parcelId > 0 && is_admin()) {
    $planCheck = $pdo->prepare('SELECT 1 FROM parcel_route_plans WHERE parcel_id = ?');
    $planCheck->execute([$parcelId]);
    $needsAdminPlan = !$planCheck->fetchColumn();
}

// The rider refreshes this after posting a new GPS location. Admin requests
// reuse that saved result after its plan has been established, preventing
// different distance/ETA values on the two screens.
if ($parcelId > 0 && !$refreshRoute && !$needsAdminPlan) {
    $snapshotStmt = $pdo->prepare('SELECT start_latitude, start_longitude, end_latitude, end_longitude, distance_km, duration_min, route_geometry, calculated_at FROM delivery_route_snapshots WHERE parcel_id = ? AND rider_id = ?');
    $snapshotStmt->execute([$parcelId, $riderId]);
    $snapshot = $snapshotStmt->fetch();

    if ($snapshot && $snapshot['route_geometry']) {
        $snapshotGeometry = json_decode($snapshot['route_geometry'], true);
        if (is_array($snapshotGeometry) && !empty($snapshotGeometry['coordinates'])) {
            echo json_encode([
                'success' => true,
                'start' => ['lat' => (float) $snapshot['start_latitude'], 'lng' => (float) $snapshot['start_longitude']],
                'end' => ['lat' => (float) $snapshot['end_latitude'], 'lng' => (float) $snapshot['end_longitude']],
                'tracking_number' => $parcel['tracking_number'] ?? null,
                'distance_km' => (float) $snapshot['distance_km'],
                'duration_min' => (float) $snapshot['duration_min'],
                'geometry' => $snapshotGeometry,
                'calculated_at' => $snapshot['calculated_at'],
            ]);
            exit;
        }
    }
}

$result = ors_post_json('/v2/directions/driving-car/geojson', [
    // ORS expects [longitude, latitude]. The GeoJSON endpoint returns the
    // actual road geometry plus summary distance (meters) and duration (seconds).
    'coordinates' => [
        [$startLng, $startLat],
        [$endLng, $endLat],
    ],
]);

// Older parcels can contain an incorrect saved coordinate (for example, from a
// browser location lookup). If that point is not close to a routable road,
// refresh it from the saved delivery address and retry once.
if (!$result['ok'] && $usedStoredRecipientCoordinates && isset($parcel['recipient_address'])) {
    $coordinates = geocode_malaysia_address($parcel['recipient_address']);
    if ($coordinates) {
        $endLat = $coordinates['lat'];
        $endLng = $coordinates['lng'];
        $pdo->prepare('UPDATE parcels SET recipient_latitude = ?, recipient_longitude = ?, recipient_geocoded_at = NOW() WHERE id = ?')
            ->execute([$endLat, $endLng, $parcelId]);
        $result = ors_post_json('/v2/directions/driving-car/geojson', [
            'coordinates' => [
                [$startLng, $startLat],
                [$endLng, $endLat],
            ],
        ]);
    }
}

if (!$result['ok'] || empty($result['data']['features'][0])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Route calculation failed.']);
    exit;
}

$feature = $result['data']['features'][0];
$summary = $feature['properties']['summary'] ?? [];
$geometry = $feature['geometry'] ?? null;

if (!$geometry || ($geometry['type'] ?? '') !== 'LineString' || empty($geometry['coordinates'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'OpenRouteService returned no road geometry for this route.']);
    exit;
}

$distanceKm = isset($summary['distance']) ? round($summary['distance'] / 1000, 2) : null;
$durationMin = isset($summary['duration']) ? round($summary['duration'] / 60, 1) : null;

if ($parcelId > 0) {
    $pdo->prepare('INSERT INTO delivery_route_snapshots (parcel_id, rider_id, start_latitude, start_longitude, end_latitude, end_longitude, distance_km, duration_min, route_geometry)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rider_id = VALUES(rider_id), start_latitude = VALUES(start_latitude), start_longitude = VALUES(start_longitude), end_latitude = VALUES(end_latitude), end_longitude = VALUES(end_longitude), distance_km = VALUES(distance_km), duration_min = VALUES(duration_min), route_geometry = VALUES(route_geometry)')
        ->execute([$parcelId, $riderId, $startLat, $startLng, $endLat, $endLng, $distanceKm, $durationMin, json_encode($geometry)]);

    // Keep the first administrator-created A-to-C route as the plan. Rider
    // refreshes update only the live snapshot, never this baseline route.
    if (is_admin()) {
        $pdo->prepare('INSERT IGNORE INTO parcel_route_plans (parcel_id, rider_id, start_latitude, start_longitude, end_latitude, end_longitude, distance_km, duration_min, route_geometry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$parcelId, $riderId, $startLat, $startLng, $endLat, $endLng, $distanceKm, $durationMin, json_encode($geometry)]);
    }
}

echo json_encode([
    'success' => true,
    'start' => ['lat' => $startLat, 'lng' => $startLng],
    'end' => ['lat' => $endLat, 'lng' => $endLng],
    'tracking_number' => $parcel['tracking_number'] ?? null,
    'distance_km' => $distanceKm,
    'duration_min' => $durationMin,
    'geometry' => $geometry,
]);
