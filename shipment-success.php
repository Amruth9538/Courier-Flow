<?php
require_once __DIR__ . '/config.php';
require_auth();
$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}
$tracking = strtoupper(trim((string) ($_GET['tracking'] ?? '')));
$sql = 'SELECT id, tracking_no, status, receiver_name, receiver_city, receiver_country, created_at FROM shipments WHERE tracking_no = ?';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shipment Booked | CourierFlow</title>
    <link rel="stylesheet" href="assets/css/shipment.css">
</head>
<body class="shipment-page">
<main class="shipment-shell">
    <section class="shipment-card shipment-error-page">
        <div class="shipment-success">Shipment booked successfully.</div>
        <h1>Booking confirmed</h1>
        <p>Your tracking number is:</p>
        <p style="font-size:clamp(24px,5vw,40px);font-weight:800;color:var(--ship-accent)"><?= $h($shipment['tracking_no']) ?></p>
        <p>Status: <strong><?= $h($shipment['status']) ?></strong></p>
        <p>Recipient: <?= $h($shipment['receiver_name']) ?>, <?= $h($shipment['receiver_city']) ?>, <?= $h($shipment['receiver_country']) ?></p>
        <div class="shipment-buttons">
            <button type="button" class="shipment-btn" onclick="window.print()">Print Label</button>
            <a class="shipment-btn secondary" target="_blank" rel="noopener" href="invoice-preview.php?tracking=<?= rawurlencode($shipment['tracking_no']) ?>">Preview Invoice</a>
            <a class="shipment-btn secondary" href="shipment-list.php">Shipment List</a>
            <a class="shipment-btn secondary" href="create-shipment.php">Book Another</a>
        </div>
    </section>
</main>
</body>
</html>
