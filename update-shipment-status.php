<?php
require_once __DIR__ . '/config.php';
require_auth();

if (!is_admin()) {
    http_response_code(403);
    exit('Only administrators can update international shipment status.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$tracking = strtoupper(trim((string) ($_POST['tracking_no'] ?? '')));
$requestedStatus = trim((string) ($_POST['status'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
if (!preg_match('/^[A-Z0-9][A-Z0-9-]{0,39}$/', $tracking)) {
    http_response_code(400);
    exit('Invalid international tracking number.');
}
if (mb_strlen($note) > 255) {
    http_response_code(400);
    exit('Status note must not exceed 255 characters.');
}

$flow = ['Booked', 'Picked Up', 'Customs Clearance', 'In Transit', 'Out for Delivery', 'Delivered'];
try {
    verify_csrf();
    $pdo->beginTransaction();

    $lookup = $pdo->prepare(
        "SELECT id, status FROM shipments
         WHERE tracking_no = ? AND shipment_type = 'international'
         FOR UPDATE"
    );
    $lookup->execute([$tracking]);
    $shipment = $lookup->fetch();
    if (!$shipment) {
        throw new RuntimeException('International shipment not found.');
    }

    $currentStatus = (string) $shipment['status'];
    if ($requestedStatus === 'Cancelled') {
        if (in_array($currentStatus, ['Delivered', 'Cancelled'], true)) {
            throw new RuntimeException('Delivered or cancelled shipments cannot be cancelled.');
        }
    } else {
        $currentIndex = array_search($currentStatus, $flow, true);
        if ($currentIndex === false || !isset($flow[$currentIndex + 1])
            || $flow[$currentIndex + 1] !== $requestedStatus) {
            throw new RuntimeException('Select only the next status in the international shipment flow.');
        }
    }

    $update = $pdo->prepare(
        "UPDATE shipments SET status = ? WHERE id = ? AND shipment_type = 'international'"
    );
    $update->execute([$requestedStatus, (int) $shipment['id']]);
    $history = $pdo->prepare(
        'INSERT INTO shipment_status_history (shipment_id, status, note) VALUES (?, ?, ?)'
    );
    $history->execute([
        (int) $shipment['id'],
        $requestedStatus,
        $note !== '' ? $note : 'Status updated by administrator.',
    ]);

    $pdo->commit();
    $_SESSION['shipment_status_message'] = 'Shipment status updated.';
    redirect('shipment-list.php');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException) {
        error_log('International shipment status update failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to update shipment status. Confirm database_split.sql has been applied.');
    }
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
