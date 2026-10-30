<?php
require_once __DIR__ . '/../config.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$parcelId = (int) ($input['parcel_id'] ?? 0);
$status = $input['status'] ?? 'Pending';
$remarks = $input['remarks'] ?? '';

if (!is_rider()) {
    echo json_encode(['success' => false, 'message' => 'Only riders can update parcel status']);
    exit;
}

$pdo->prepare('UPDATE parcels SET status = ? WHERE id = ?')->execute([$status, $parcelId]);
$pdo->prepare('INSERT INTO parcel_status_history (parcel_id, status, remarks, created_by) VALUES (?, ?, ?, ?)')->execute([$parcelId, $status, $remarks, $_SESSION['user_id']]);
$pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
    $_SESSION['user_id'],
    'Parcel Status',
    'Parcel status changed to ' . $status
]);

echo json_encode(['success' => true, 'message' => 'Status updated']);
