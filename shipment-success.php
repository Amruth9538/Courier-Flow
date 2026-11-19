<?php
require_once __DIR__ . '/config.php';
require_auth();
$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}
$tracking = strtoupper(trim((string) ($_GET['tracking'] ?? '')));
$columns = $pdo->query('SHOW COLUMNS FROM shipments')->fetchAll(PDO::FETCH_COLUMN);
$hasShipmentType = in_array('shipment_type', $columns, true);
$shipmentTypeSql = $hasShipmentType ? 'shipment_type' : "'international' AS shipment_type";
$sql = 'SELECT id, tracking_no, status, receiver_name, receiver_city, receiver_country, created_at, ' . $shipmentTypeSql . ' FROM shipments WHERE tracking_no = ?';
if ($role !== 'admin') {
    $sql .= ' AND user_id = ?';
}
$stmt = $pdo->prepare($sql);
$params = [$tracking];
if ($role !== 'admin') {
    $params[] = (int) $_SESSION['user_id'];
}
$stmt->execute($params);
$shipment = $stmt->fetch();
if (!$shipment) {
    http_response_code(404);
    exit('Shipment not found.');
}
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$domestic = ($shipment['shipment_type'] ?? '') === 'domestic';
$listUrl = $domestic ? 'parcel-list.php' : 'shipment-list.php';
$createUrl = $domestic ? 'parcels-management.php' : 'create-shipment.php';
$bookingLabel = $domestic ? 'Domestic parcel' : 'International shipment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $domestic ? 'Parcel Booked' : 'Shipment Booked' ?> | CourierFlow</title>
    <link rel="stylesheet" href="assets/css/shipment.css">
</head>
<body class="shipment-page">
<main class="shipment-shell">
    <section class="shipment-card shipment-error-page">
        <div class="shipment-success"><?= $h($bookingLabel) ?> booked successfully.</div>
        <h1>Booking confirmed</h1>
        <p>Your tracking number is:</p>
        <p style="font-size:clamp(24px,5vw,40px);font-weight:800;color:var(--ship-accent)"><?= $h($shipment['tracking_no']) ?></p>
        <p>Status: <strong><?= $h($shipment['status']) ?></strong></p>
        <p><?= $domestic ? 'Receiver' : 'Recipient' ?>: <?= $h($shipment['receiver_name']) ?>, <?= $h($shipment['receiver_city']) ?>, <?= $h($shipment['receiver_country']) ?></p>
        <div class="shipment-buttons">
            <button type="button" class="shipment-btn" onclick="window.print()">Print Label</button>
            <?php if (!$domestic): ?>
                <a class="shipment-btn secondary" target="_blank" rel="noopener" href="invoice-preview.php?tracking=<?= rawurlencode($shipment['tracking_no']) ?>">Preview Invoice</a>
            <?php endif; ?>
            <a class="shipment-btn secondary" href="<?= $listUrl ?>"><?= $domestic ? 'Domestic Parcels' : 'International Shipments' ?></a>
            <a class="shipment-btn secondary" href="<?= $createUrl ?>">Book Another</a>
        </div>
    </section>
</main>
</body>
</html>
