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
$allowedStatuses = ['Pending', 'In Transit', 'Out for Delivery', 'Delivered', 'Failed Delivery', 'Cancelled'];

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
        SELECT id, is_online
        FROM riders
        WHERE user_id = ?
        FOR UPDATE
    ");
    $riderStmt->execute([$userId]);
    $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);
    $riderId = (int) ($rider['id'] ?? 0);

    if (!$riderId || (int) $parcel['assigned_rider_id'] !== $riderId) {
        $pdo->rollBack();
        status_response(false, 'This parcel is not assigned to you', 403);
    }

    $otherActiveStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM parcels
        WHERE assigned_rider_id = ?
          AND id <> ?
          AND status NOT IN ('Delivered', 'Cancelled')
    ");
    $otherActiveStmt->execute([$riderId, $parcelId]);
    if ((int) $otherActiveStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        status_response(false, 'Complete your current parcel before updating another assignment', 409);
    }

    // Avoid duplicate notifications for unchanged statuses
    if ($parcel['status'] === $status) {
        $pdo->commit();
        status_response(true, 'Parcel already has this status');
    }

    if (!can_transition_parcel_status((string) $parcel['status'], $status)) {
        $pdo->rollBack();
        status_response(false, 'Select only the next allowed parcel status', 409);
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

    if (in_array($status, ['Delivered', 'Cancelled'], true)) {
        $activeStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM parcels
            WHERE assigned_rider_id = ?
              AND status NOT IN ('Delivered', 'Cancelled')
        ");
        $activeStmt->execute([$riderId]);
        $activeCount = (int) $activeStmt->fetchColumn();
        $waitingParcelId = null;

        if ($activeCount === 0 && (int) $rider['is_online'] === 1) {
            $waitingStmt = $pdo->prepare("
                SELECT id
                FROM parcels
                WHERE assigned_rider_id IS NULL
                  AND status = 'Pending'
                ORDER BY created_at ASC, id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $waitingStmt->execute();
            $waitingParcelId = $waitingStmt->fetchColumn();
        }

        if ($waitingParcelId) {
            $assignStmt = $pdo->prepare("
                UPDATE parcels
                SET assigned_rider_id = ?
                WHERE id = ?
                  AND assigned_rider_id IS NULL
                  AND status = 'Pending'
            ");
            $assignStmt->execute([$riderId, $waitingParcelId]);

            if ($assignStmt->rowCount() > 0) {
                $pdo->prepare('UPDATE riders SET is_available = 0 WHERE id = ?')
                    ->execute([$riderId]);
                $waitingParcel = $pdo->prepare('SELECT customer_id, tracking_number FROM parcels WHERE id = ?');
                $waitingParcel->execute([$waitingParcelId]);
                $waitingDetails = $waitingParcel->fetch(PDO::FETCH_ASSOC);
                if ($waitingDetails && !empty($waitingDetails['customer_id'])) {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, parcel_id, title, message, is_read)
                        VALUES (?, ?, 'Rider Assigned', ?, 0)
                    ")->execute([
                        (int) $waitingDetails['customer_id'],
                        (int) $waitingParcelId,
                        'Your parcel ' . $waitingDetails['tracking_number'] . ' has been assigned to a delivery rider.'
                    ]);
                }
            }
        } else {
            $pdo->prepare('UPDATE riders SET is_available = IF(is_online = 1, 1, 0) WHERE id = ?')
                ->execute([$riderId]);
        }
    } else {
        $pdo->prepare('UPDATE riders SET is_available = 0 WHERE id = ?')
            ->execute([$riderId]);
    }

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
            'Out for Delivery' => 'Your parcel is out for delivery.',
            'Delivered' => 'Your parcel has been delivered successfully.',
            'Failed Delivery' => 'A delivery attempt was unsuccessful.',
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