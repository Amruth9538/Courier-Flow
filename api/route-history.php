<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$trackingNumber = strtoupper(trim((string) ($_GET['tracking'] ?? '')));
if ($trackingNumber === '' || !preg_match('/^[A-Z0-9-]{4,50}$/', $trackingNumber)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid tracking number is required.']);
    exit;
}

$parcelStmt = $pdo->prepare('SELECT p.id, p.tracking_number, p.status, p.recipient_latitude, p.recipient_longitude, r.current_latitude, r.current_longitude, r.last_seen FROM parcels p LEFT JOIN riders r ON r.id = p.assigned_rider_id WHERE p.tracking_number = ?');
$parcelStmt->execute([$trackingNumber]);
$parcel = $parcelStmt->fetch();
if (!$parcel) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Parcel not found.']);
    exit;
}

$planStmt = $pdo->prepare('SELECT start_latitude, start_longitude, end_latitude, end_longitude, distance_km, duration_min, route_geometry, planned_at FROM parcel_route_plans WHERE parcel_id = ?');
$planStmt->execute([$parcel['id']]);
$plan = $planStmt->fetch();
if ($plan) {
    $plan['geometry'] = json_decode($plan['route_geometry'], true);
    unset($plan['route_geometry']);
}

$historyStmt = $pdo->prepare('SELECT latitude, longitude, recorded_at FROM parcel_route_history WHERE parcel_id = ? ORDER BY recorded_at ASC, id ASC');
// Get the latest calculated delivery distance and time
$etaStmt = $pdo->prepare("
    SELECT distance_km, duration_min, calculated_at
    FROM delivery_route_snapshots
    WHERE parcel_id = ?
    LIMIT 1
");

$etaStmt->execute([$parcel['id']]);
$eta = $etaStmt->fetch();
$historyStmt->execute([$parcel['id']]);

echo json_encode([
    'success' => true,
    'tracking_number' => $parcel['tracking_number'],
    'status' => $parcel['status'],
        'distance_km' => $eta && $eta['distance_km'] !== null
        ? (float) $eta['distance_km'] : null,

    'duration_min' => $eta && $eta['duration_min'] !== null
        ? (float) $eta['duration_min'] : null,

    'eta_updated_at' => $eta['calculated_at'] ?? null,
    'plan' => $plan ?: null,
    'actual_points' => $historyStmt->fetchAll(),
    'destination' => $parcel['recipient_latitude'] !== null ? [
        'lat' => (float) $parcel['recipient_latitude'],
        'lng' => (float) $parcel['recipient_longitude'],
    ] : null,
    // This is the rider's live location. It remains available even when the
    // delivery began before parcel-specific GPS history was introduced.
    'latest_rider_position' => $parcel['current_latitude'] !== null && $parcel['current_longitude'] !== null ? [
        'lat' => (float) $parcel['current_latitude'],
        'lng' => (float) $parcel['current_longitude'],
        'last_seen' => $parcel['last_seen'],
    ] : null,
]);
