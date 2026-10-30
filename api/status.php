<?php
require_once __DIR__ . '/../config.php';
require_auth();

header('Content-Type: application/json');

function status_response($success, $message, $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

// Only POST requests are allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    status_response(false, 'Method not allowed', 405);
}

// Only riders can update parcel status
if (!is_rider()) {
    status_response(false, 'Only riders can update parcel status', 403);
}

// Read request data
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    status_response(false, 'Invalid request data', 400);
}

$parcelId = (int) ($input['parcel_id'] ?? 0);
$status = $input['status'] ?? '';
$remarks = trim((string) ($input['remarks'] ?? ''));

// Validate parcel ID and status
$allowedStatuses = ['Pending', 'In Transit', 'Delivered', 'Cancelled'];

if ($parcelId <= 0 || !is_string($status) || !in_array($status, $allowedStatuses, true)) {
    status_response(false, 'Invalid parcel ID or status', 400);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    $pdo->beginTransaction();

    // Find the parcel and lock it during the update
    $stmt = $pdo->prepare("
        SELECT id, customer_id, assigned_rider_id, tracking_number, status
        FROM parcels
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$parcelId]);
    $parcel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$parcel) {
        $pdo->rollBack();
        status_response(false, 'Parcel not found', 404);
    }

    // Check that the logged-in rider is assigned to this parcel
    $riderStmt = $pdo->prepare("
        SELECT id
        FROM riders
        WHERE user_id = ?
    ");
    $riderStmt->execute([$userId]);
    $riderId = (int) $riderStmt->fetchColumn();

    if (!$riderId || (int) $parcel['assigned_rider_id'] !== $riderId) {
        $pdo->rollBack();
        status_response(false, 'This parcel is not assigned to you', 403);
    }

    // Avoid duplicate notifications for unchanged statuses
    if ($parcel['status'] === $status) {
        $pdo->commit();
        status_response(true, 'Parcel already has this status');
    }

    // Update parcel status
    $pdo->prepare("
        UPDATE parcels
        SET status = ?
        WHERE id = ?
    ")->execute([$status, $parcelId]);

    // Save status history
    $pdo->prepare("
        INSERT INTO parcel_status_history
            (parcel_id, status, remarks, created_by)
        VALUES (?, ?, ?, ?)
    ")->execute([$parcelId, $status, $remarks, $userId]);

    // Save activity log
    $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, details)
        VALUES (?, ?, ?)
    ")->execute([
        $userId,
        'Parcel Status',
        'Parcel status changed to ' . $status
    ]);

    // Create a notification for the customer
    $customerId = (int) ($parcel['customer_id'] ?? 0);

    if ($customerId > 0) {
        $trackingNumber = $parcel['tracking_number'];

        $messages = [
            'Pending' => 'Your parcel is pending.',
            'In Transit' => 'Your parcel is on the way.',
            'Delivered' => 'Your parcel has been delivered successfully.',
            'Cancelled' => 'Your parcel has been cancelled.'
        ];

        $title = 'Parcel Status Updated';
        $message = $messages[$status] . ' Tracking Number: ' . $trackingNumber;

        $pdo->prepare("
            INSERT INTO notifications
                (user_id, parcel_id, title, message, is_read)
            VALUES (?, ?, ?, ?, 0)
        ")->execute([
            $customerId,
            $parcelId,
            $title,
            $message
        ]);
    }

    $pdo->commit();

    status_response(true, 'Status updated and customer notified');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Parcel status update failed: ' . $e->getMessage());
    status_response(false, 'Unable to update parcel status', 500);
}