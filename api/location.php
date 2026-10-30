<?php
require_once __DIR__ . '/../config.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$lat = (float) ($input['lat'] ?? 0);
$lng = (float) ($input['lng'] ?? 0);

if (!is_rider()) {
    echo json_encode(['success' => false, 'message' => 'Only riders can update location']);
    exit;
}

$rider = ensure_rider_profile();
if (!$rider) {
    echo json_encode(['success' => false, 'message' => 'Rider profile not found']);
    exit;
}

$pdo->prepare('UPDATE riders SET current_latitude = ?, current_longitude = ?, last_seen = NOW() WHERE id = ?')->execute([$lat, $lng, $rider['id']]);
$pdo->prepare('INSERT INTO rider_locations (rider_id, latitude, longitude) VALUES (?, ?, ?)')->execute([$rider['id'], $lat, $lng]);

// Preserve the actual GPS path for the parcel currently being delivered.
// Unlike rider_locations, these points are linked to one specific delivery.
$activeParcel = $pdo->prepare("SELECT id FROM parcels WHERE assigned_rider_id = ? AND status NOT IN ('Delivered', 'Cancelled') ORDER BY CASE WHEN status IN ('In Transit', 'Out for Delivery') THEN 0 ELSE 1 END, created_at DESC LIMIT 1");
$activeParcel->execute([$rider['id']]);
$activeParcelId = $activeParcel->fetchColumn();
if ($activeParcelId) {
    $pdo->prepare('INSERT INTO parcel_route_history (parcel_id, rider_id, latitude, longitude) VALUES (?, ?, ?, ?)')
        ->execute([(int) $activeParcelId, $rider['id'], $lat, $lng]);
}
$pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
    $_SESSION['user_id'],
    'Location Update',
    'Location updated to ' . $lat . ', ' . $lng
]);

echo json_encode(['success' => true, 'message' => 'Location updated']);
