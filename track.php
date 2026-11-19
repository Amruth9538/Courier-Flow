<?php
require_once __DIR__ . '/config.php';

$trackingNumber = strtoupper(trim((string) ($_GET['tracking'] ?? '')));
$parcel = null;
if ($trackingNumber !== '') {
    $stmt = $pdo->prepare('SELECT tracking_number, recipient_name, status FROM parcels WHERE tracking_number = ?');
    $stmt->execute([$trackingNumber]);
    $parcel = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Delivery | CourierFlow</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body style="background:#f1f5f9;">
<main style="max-width:1100px; margin:40px auto; padding:0 20px;">
    <button class="btn btn-secondary page-back-button" type="button" data-back-button data-back-fallback="index.php">
        Back
    </button>
    <div class="panel">
        <h1 style="margin-top:0;">CourierFlow Delivery Tracking</h1>
        <?php if (!$parcel): ?>
            <p class="alert alert-danger">Tracking number not found.</p>
        <?php else: ?>
            <p><strong>Tracking:</strong> <?= htmlspecialchars($parcel['tracking_number'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Recipient:</strong> <?= htmlspecialchars($parcel['recipient_name'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong>Status:</strong> <?= htmlspecialchars($parcel['status'], ENT_QUOTES, 'UTF-8') ?></p>
            <!-- Estimated Delivery Information -->
<div style="display:flex; gap:15px; flex-wrap:wrap; margin:20px 0;">

    <!-- Distance Remaining -->
    <div style="background:#eff6ff; padding:20px; border-radius:12px; flex:1; min-width:200px;">
        <h3 style="color:#1d4ed8; margin-top:0;">
            <i class="bi bi-geo-alt-fill"></i> Distance Remaining
        </h3>

        <p id="distance-remaining" style="font-size:26px; font-weight:bold;">
            Calculating...
        </p>
    </div>

    <!-- Estimated Delivery Time -->
    <div style="background:#f0fdf4; padding:20px; border-radius:12px; flex:1; min-width:200px;">
        <h3 style="color:#15803d; margin-top:0;">
            <i class="bi bi-clock-fill"></i> Estimated Delivery Time
        </h3>

        <p id="estimated-delivery-time" style="font-size:26px; font-weight:bold;">
            Calculating...
        </p>
    </div>

</div>
            <p id="route-history-message" class="muted">Loading delivery route…</p>
            <div id="route-history-map" class="map" style="height: 580px !important; min-height: 580px !important;" data-tracking="<?= htmlspecialchars($parcel['tracking_number'], ENT_QUOTES, 'UTF-8') ?>" data-api-base="api/"></div>
            <p class="muted" style="margin-top:12px;">Blue dashed: planned route. Orange: actual route travelled by your rider.</p>
        <?php endif; ?>
    </div>
</main>
<?php if ($parcel): ?><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script src="assets/js/route-history.js"></script><?php endif; ?>
<script src="assets/js/back-navigation.js"></script>
</body>
</html>
