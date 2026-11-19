<?php
require_once __DIR__ . '/config.php';
require_auth();

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer', 'rider'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}

$shipmentSchemaReady = (bool) $pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn();
$assignedColumnReady = false;
$shipmentTypeReady = false;
$domesticColumnsReady = false;
$declaredValueExpression = 's.total_value AS declared_value';
$paymentModeExpression = "'N/A' AS payment_mode";
if ($shipmentSchemaReady) {
    $shipmentColumns = $pdo->query('SHOW COLUMNS FROM shipments')->fetchAll(PDO::FETCH_COLUMN);
    $assignedColumnReady = in_array('assigned_rider_id', $shipmentColumns, true);
    $shipmentTypeReady = in_array('shipment_type', $shipmentColumns, true);
    $hasDeclaredValue = in_array('declared_value', $shipmentColumns, true);
    $hasPaymentMode = in_array('payment_mode', $shipmentColumns, true);
    $declaredValueExpression = $hasDeclaredValue ? 's.declared_value' : 's.total_value AS declared_value';
    $paymentModeExpression = $hasPaymentMode ? 's.payment_mode' : "'N/A' AS payment_mode";
    $domesticColumnsReady = $hasDeclaredValue && $hasPaymentMode;
}
$domesticRatesReady = (bool) $pdo->query("SHOW TABLES LIKE 'domestic_rates'")->fetchColumn();

$statuses = ['Booked', 'Picked Up', 'In Transit', 'Out for Delivery', 'Delivered', 'Cancelled'];
$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$status = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($datePattern, $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match($datePattern, $dateTo)) {
    $dateTo = '';
}

$shipments = [];
$riderProfileId = 0;
if ($role === 'rider') {
    $riderStmt = $pdo->prepare('SELECT id FROM riders WHERE user_id = ?');
    $riderStmt->execute([(int) $_SESSION['user_id']]);
    $riderProfileId = (int) ($riderStmt->fetchColumn() ?: 0);
}

if ($shipmentSchemaReady && $shipmentTypeReady && ($role !== 'rider' || $assignedColumnReady)) {
    $where = ["s.shipment_type = 'domestic'"];
    $params = [];
    if ($role === 'customer') {
        $where[] = 's.user_id = ?';
        $params[] = (int) $_SESSION['user_id'];
    } elseif ($role === 'rider') {
        $where[] = 's.assigned_rider_id = ?';
        $params[] = $riderProfileId;
    }
    if ($search !== '') {
        $where[] = '(s.tracking_no LIKE ? OR s.receiver_name LIKE ? OR s.shipper_name LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term);
    }
    if (in_array($status, $statuses, true)) {
        $where[] = 's.status = ?';
        $params[] = $status;
    }
    if ($dateFrom !== '') {
        $where[] = 's.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = 's.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $dateTo;
    }

    $sql = "SELECT s.id, s.tracking_no, s.status, s.shipper_name, s.shipper_city,
                   s.receiver_name, s.receiver_city, s.pieces, s.chg_wt,
                   {$declaredValueExpression}, {$paymentModeExpression}, s.created_at
            FROM shipments s
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.created_at DESC, s.id DESC
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shipments = $stmt->fetchAll();
}

$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$dashboardUrl = $role === 'admin' ? 'dashboard.php' : ($role === 'rider' ? 'rider-dashboard.php' : 'customer-dashboard.php');
$bodyAttributes = 'class="shipment-page shipment-app-page"';
require_once __DIR__ . '/includes/header.php';
?>
<div class="shipment-shell">
    <header class="shipment-header">
        <div>
            <h1>Domestic Parcels</h1>
            <p class="shipment-muted">Search and filter domestic parcel bookings.</p>
        </div>
        <div class="shipment-buttons">
            <?php if ($role !== 'rider'): ?>
                <a class="shipment-btn" href="parcels-management.php">Book Domestic Parcel</a>
            <?php endif; ?>
            <a class="shipment-btn secondary" href="<?= $h($dashboardUrl) ?>">Dashboard</a>
        </div>
    </header>

    <?php if (!$shipmentSchemaReady): ?>
        <div class="shipment-error" role="alert">Shipment storage is not configured. Import <code>database_shipments.sql</code>.</div>
    <?php elseif ($role === 'rider' && !$assignedColumnReady): ?>
        <div class="shipment-error" role="alert">Rider assignment support is missing. Import the updated <code>database_split.sql</code> migration.</div>
    <?php elseif ($role === 'rider' && $riderProfileId === 0): ?>
        <div class="shipment-error" role="alert">No rider profile is linked to this account. Contact an administrator.</div>
    <?php endif; ?>
    <?php if ($shipmentSchemaReady && !$shipmentTypeReady): ?>
        <div class="shipment-error" role="alert">
            The domestic/international migration has not been applied. Import <code>database_split.sql</code> in phpMyAdmin before using the separated booking lists. This migration adds the shipment type and domestic booking columns without deleting shipment records.
        </div>
    <?php elseif ($shipmentSchemaReady && (!$domesticColumnsReady || !$domesticRatesReady)): ?>
        <div class="shipment-error" role="alert">
            Domestic booking schema is incomplete. Import <code>database_split.sql</code> in phpMyAdmin to add declared values, payment modes, and rate slabs. Existing records will remain intact.
            <?php if (!$domesticRatesReady): ?> Domestic rate table is currently missing.<?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="shipment-section">
        <form method="get" class="shipment-fields">
            <label class="shipment-field">Tracking or receiver
                <input name="search" value="<?= $h($search) ?>" maxlength="100" placeholder="Search domestic parcels">
            </label>
            <label class="shipment-field">Status
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $shipmentStatus): ?>
                        <option value="<?= $h($shipmentStatus) ?>" <?= $status === $shipmentStatus ? 'selected' : '' ?>><?= $h($shipmentStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="shipment-field">From date
                <input type="date" name="date_from" value="<?= $h($dateFrom) ?>">
            </label>
            <label class="shipment-field">To date
                <input type="date" name="date_to" value="<?= $h($dateTo) ?>">
            </label>
            <div class="shipment-buttons">
                <button class="shipment-btn" type="submit">Search</button>
                <a class="shipment-btn secondary" href="parcel-list.php">Clear</a>
            </div>
        </form>

        <div class="shipment-table-wrap" style="margin-top:20px">
            <table class="shipment-table">
                <thead>
                    <tr>
                        <th>Tracking</th><th>Status</th><th>Sender</th><th>Receiver</th>
                        <th>Pieces</th><th>Chg Wt</th><th>Declared Value</th><th>Payment</th><th>Booked</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$shipments): ?>
                    <tr><td colspan="9">No domestic parcels found.</td></tr>
                <?php endif; ?>
                <?php foreach ($shipments as $shipment): ?>
                    <tr>
                        <td><?= $h($shipment['tracking_no']) ?></td>
                        <td><?= $h($shipment['status']) ?></td>
                        <td><?= $h($shipment['shipper_name']) ?>, <?= $h($shipment['shipper_city']) ?></td>
                        <td><?= $h($shipment['receiver_name']) ?>, <?= $h($shipment['receiver_city']) ?></td>
                        <td><?= (int) $shipment['pieces'] ?></td>
                        <td><?= number_format((float) $shipment['chg_wt'], 3) ?> kg</td>
                        <td>₹<?= number_format((float) $shipment['declared_value'], 2) ?></td>
                        <td><?= $h($shipment['payment_mode']) ?></td>
                        <td><?= $h($shipment['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
