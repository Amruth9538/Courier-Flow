<?php
require_once __DIR__ . '/config.php';
require_auth();
$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}

$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$shipment = null;
$commodities = [];
$draft = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['preview_draft'] ?? '') === '1') {
    try {
        verify_csrf();
    } catch (RuntimeException $e) {
        http_response_code(403);
        exit('Invalid form token. Return to the booking page and try again.');
    }
    $draft = true;
    $shipper = is_array($_POST['shipper'] ?? null) ? $_POST['shipper'] : [];
    $receiver = is_array($_POST['receiver'] ?? null) ? $_POST['receiver'] : [];
    if (strtoupper(trim((string) ($shipper['country'] ?? ''))) !== 'INDIA') {
        http_response_code(422);
        exit('International shipments must originate in INDIA.');
    }
    if (strtoupper(trim((string) ($receiver['country'] ?? ''))) === 'INDIA') {
        http_response_code(422);
        exit('International shipments cannot be delivered to INDIA.');
    }
    if (!is_array($_POST['commodities'] ?? null) || count($_POST['commodities']) < 1) {
        http_response_code(422);
        exit('Add at least one commodity before previewing the invoice.');
    }
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
    if (!in_array($currency, ['INR', 'USD', 'EUR', 'GBP', 'AED'], true)) {
        http_response_code(422);
        exit('Select a supported invoice currency.');
    }
    $shipment = [
        'tracking_no' => 'Draft preview (not booked)',
        'shipper_name' => $shipper['name'] ?? '',
        'shipper_address' => implode(', ', array_filter([
            $shipper['address_line1'] ?? '', $shipper['address_line2'] ?? '',
            $shipper['city'] ?? '', $shipper['state'] ?? '', $shipper['postal_code'] ?? '',
            $shipper['country'] ?? '',
        ])),
        'shipper_email' => $shipper['email'] ?? '',
        'shipper_mobile' => $shipper['mobile'] ?? '',
        'receiver_name' => $receiver['name'] ?? '',
        'receiver_address' => implode(', ', array_filter([
            $receiver['address_line1'] ?? '', $receiver['address_line2'] ?? '',
            $receiver['city'] ?? '', $receiver['state'] ?? '', $receiver['postal_code'] ?? '',
            $receiver['country'] ?? '',
        ])),
        'receiver_email' => $receiver['email'] ?? '',
        'receiver_mobile' => $receiver['mobile'] ?? '',
        'currency' => $currency,
        'contents' => is_scalar($_POST['contents'] ?? '') ? (string) $_POST['contents'] : '',
        'total_value' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    foreach ($_POST['commodities'] as $commodity) {
        if (!is_array($commodity)) {
            continue;
        }
        $qty = (float) ($commodity['qty'] ?? 0);
        $price = (float) ($commodity['unit_price'] ?? 0);
        $commodity['total'] = $qty * $price;
        $shipment['total_value'] += $commodity['total'];
        $commodities[] = $commodity;
    }
} else {
    $tracking = strtoupper(trim((string) ($_GET['tracking'] ?? '')));
    $sql = "SELECT * FROM shipments WHERE tracking_no = ? AND shipment_type = 'international'";
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
    $commodityStmt = $pdo->prepare('SELECT description, hs_code, qty, unit_price, total FROM shipment_commodities WHERE shipment_id = ? ORDER BY id');
    $commodityStmt->execute([(int) $shipment['id']]);
    $commodities = $commodityStmt->fetchAll();
    $shipment['shipper_address'] = implode(', ', array_filter([
        $shipment['shipper_address_line1'], $shipment['shipper_address_line2'],
        $shipment['shipper_city'], $shipment['shipper_state'],
        $shipment['shipper_postal_code'], $shipment['shipper_country'],
    ]));
    $shipment['receiver_address'] = implode(', ', array_filter([
        $shipment['receiver_address_line1'], $shipment['receiver_address_line2'],
        $shipment['receiver_city'], $shipment['receiver_state'],
        $shipment['receiver_postal_code'], $shipment['receiver_country'],
    ]));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice Preview | CourierFlow</title>
    <link rel="stylesheet" href="assets/css/shipment.css">
    <style>
        .invoice-sheet{max-width:900px;margin:30px auto;background:#fff;color:#172033;padding:38px;border-radius:12px}
        .invoice-sheet h1,.invoice-sheet h2{color:#14345d}.invoice-columns{display:grid;grid-template-columns:1fr 1fr;gap:24px}
        .invoice-sheet table{width:100%;border-collapse:collapse}.invoice-sheet th,.invoice-sheet td{padding:10px;border-bottom:1px solid #dfe7f1;text-align:left}
        .invoice-sheet th{color:#14345d}.invoice-actions{max-width:900px;margin:auto}
        @media print{body.shipment-page{padding:0;background:#fff}.invoice-sheet{margin:0 auto;box-shadow:none}.invoice-actions{display:none}}
    </style>
</head>
<body class="shipment-page">
<div class="invoice-actions shipment-buttons">
    <?php if ($draft): ?><span class="shipment-note">Draft preview only — no shipment has been booked.</span><?php endif; ?>
    <button class="shipment-btn" onclick="window.print()" type="button">Print Invoice</button>
    <button class="shipment-btn secondary" onclick="window.close()" type="button">Close</button>
</div>
<main class="invoice-sheet">
    <div class="shipment-title-row"><h1>CourierFlow Shipment Invoice</h1><strong><?= $h($shipment['tracking_no']) ?></strong></div>
    <p>Created: <?= $h($shipment['created_at']) ?> | Contents: <?= $h($shipment['contents']) ?></p>
    <div class="invoice-columns">
        <section><h2>Shipper</h2><strong><?= $h($shipment['shipper_name']) ?></strong><p><?= $h($shipment['shipper_address']) ?></p><p><?= $h($shipment['shipper_email']) ?><br><?= $h($shipment['shipper_mobile']) ?></p></section>
        <section><h2>Receiver</h2><strong><?= $h($shipment['receiver_name']) ?></strong><p><?= $h($shipment['receiver_address']) ?></p><p><?= $h($shipment['receiver_email']) ?><br><?= $h($shipment['receiver_mobile']) ?></p></section>
    </div>
    <h2>Commodity details</h2>
    <table><thead><tr><th>Description</th><th>HS code</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr></thead><tbody>
        <?php foreach ($commodities as $commodity): ?>
            <tr><td><?= $h($commodity['description'] ?? '') ?></td><td><?= $h($commodity['hs_code'] ?? '') ?></td><td><?= $h($commodity['qty'] ?? '') ?></td><td><?= $h($commodity['unit_price'] ?? '') ?></td><td><?= $h($commodity['total'] ?? '') ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <p style="text-align:right;font-size:20px"><strong>Total: <?= $h($shipment['currency']) ?> <?= number_format((float) $shipment['total_value'], 2) ?></strong></p>
</main>
</body>
</html>
