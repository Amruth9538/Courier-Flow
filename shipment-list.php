<?php
require_once __DIR__ . '/config.php';
require_auth();
$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer', 'rider'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}

$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$status = trim((string) ($_GET['status'] ?? ''));
$statuses = ['Booked', 'Picked Up', 'Customs Clearance', 'In Transit', 'Out for Delivery', 'Delivered', 'Cancelled'];
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($datePattern, $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match($datePattern, $dateTo)) {
    $dateTo = '';
}
$shipmentSchemaReady = (bool) $pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn();
$shipmentColumns = $shipmentSchemaReady ? $pdo->query('SHOW COLUMNS FROM shipments')->fetchAll(PDO::FETCH_COLUMN) : [];
$assignmentReady = in_array('assigned_rider_id', $shipmentColumns, true);
$shipmentTypeReady = in_array('shipment_type', $shipmentColumns, true);
$where = ["shipment_type = 'international'"];
$params = [];
if ($role === 'customer') {
    $where[] = 'user_id = ?';
    $params[] = (int) $_SESSION['user_id'];
} elseif ($role === 'rider') {
    $riderStmt = $pdo->prepare('SELECT id FROM riders WHERE user_id = ?');
    $riderStmt->execute([(int) $_SESSION['user_id']]);
    $riderId = (int) ($riderStmt->fetchColumn() ?: 0);
    $where[] = 'assigned_rider_id = ?';
    $params[] = $riderId;
}
if ($search !== '') {
    $where[] = '(tracking_no LIKE ? OR receiver_name LIKE ? OR shipper_name LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}
if (in_array($status, $statuses, true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;
}
$shipments = [];
$statusMessage = (string) ($_SESSION['shipment_status_message'] ?? '');
unset($_SESSION['shipment_status_message']);
if ($shipmentSchemaReady && $shipmentTypeReady && ($role !== 'rider' || $assignmentReady)) {
    $sql = 'SELECT id, tracking_no, status, receiver_name, receiver_city, receiver_country, pieces, chg_wt, currency, total_value, created_at FROM shipments';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $shipments = $stmt->fetchAll();
}
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$dashboardUrl = $role === 'admin' ? 'dashboard.php' : ($role === 'rider' ? 'rider-dashboard.php' : 'customer-dashboard.php');
?>
<?php
$bodyAttributes = 'class="shipment-page shipment-app-page"';
require_once __DIR__ . '/includes/header.php';
?>
<div class="shipment-shell">
    <header class="shipment-header">
        <div><h1>International Shipments</h1><p class="shipment-muted">Search and manage international bookings.</p></div>
        <div class="shipment-buttons">
            <?php if ($role !== 'rider'): ?><a class="shipment-btn" href="create-shipment.php">Create International Shipment</a><?php endif; ?>
            <a class="shipment-btn secondary" href="<?= $h($dashboardUrl) ?>">Dashboard</a>
        </div>
    </header>
    <section class="shipment-section">
        <?php if ($statusMessage !== ''): ?>
            <div class="shipment-success" role="status"><?= $h($statusMessage) ?></div>
        <?php endif; ?>
        <?php if (!$shipmentSchemaReady): ?>
            <div class="shipment-error">Shipment tables are not set up yet. Import <code>database_shipments.sql</code> into the parcel_delivery_system database, then reload this page.</div>
        <?php elseif (!$shipmentTypeReady): ?>
            <div class="shipment-error" role="alert">The domestic/international migration has not been applied. Import <code>database_split.sql</code> in phpMyAdmin before using the separated booking lists. This migration adds the shipment type and domestic booking columns without deleting shipment records.</div>
        <?php elseif ($role === 'rider' && !$assignmentReady): ?>
            <div class="shipment-error">Rider assignment support is missing. Import the updated <code>database_split.sql</code> migration.</div>
        <?php endif; ?>
        <form method="get" class="shipment-fields">
            <label class="shipment-field">Tracking or receiver<input name="search" value="<?= $h($search) ?>" maxlength="100" placeholder="Search shipments"></label>
            <label class="shipment-field">Status<select name="status"><option value="">All statuses</option>
                <?php foreach ($statuses as $shipmentStatus): ?><option value="<?= $h($shipmentStatus) ?>" <?= $status === $shipmentStatus ? 'selected' : '' ?>><?= $h($shipmentStatus) ?></option><?php endforeach; ?>
            </select></label>
            <label class="shipment-field">From date<input type="date" name="date_from" value="<?= $h($dateFrom) ?>"></label>
            <label class="shipment-field">To date<input type="date" name="date_to" value="<?= $h($dateTo) ?>"></label>
            <div class="shipment-buttons"><button class="shipment-btn" type="submit">Search</button><a class="shipment-btn secondary" href="shipment-list.php">Clear</a></div>
        </form>
        <div class="shipment-table-wrap" style="margin-top:20px">
            <table class="shipment-table">
                <thead><tr><th>Tracking</th><th>Status</th><th>Recipient</th><th>Destination</th><th>Pieces</th><th>Chargeable wt.</th><th>Value</th><th>Created</th><th>Invoice</th><?php if ($role === 'admin'): ?><th>Update status</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$shipments): ?><tr><td colspan="<?= $role === 'admin' ? '10' : '9' ?>">No international shipments found.</td></tr><?php endif; ?>
                <?php foreach ($shipments as $shipment): ?>
                    <tr>
                        <td><a href="shipment-success.php?tracking=<?= rawurlencode($shipment['tracking_no']) ?>"><?= $h($shipment['tracking_no']) ?></a></td>
                        <td><?= $h($shipment['status']) ?></td>
                        <td><?= $h($shipment['receiver_name']) ?></td>
                        <td><?= $h($shipment['receiver_city']) ?>, <?= $h($shipment['receiver_country']) ?></td>
                        <td><?= (int) $shipment['pieces'] ?></td>
                        <td><?= number_format((float) $shipment['chg_wt'], 3) ?> kg</td>
                        <td><?= $h($shipment['currency']) ?> <?= number_format((float) $shipment['total_value'], 2) ?></td>
                        <td><?= $h($shipment['created_at']) ?></td>
                        <td><a class="shipment-btn secondary" target="_blank" rel="noopener" href="invoice-preview.php?tracking=<?= rawurlencode($shipment['tracking_no']) ?>">Preview</a></td>
                        <?php if ($role === 'admin'): ?>
                            <?php
                            $shipmentFlow = ['Booked', 'Picked Up', 'Customs Clearance', 'In Transit', 'Out for Delivery', 'Delivered'];
                            $shipmentFlowIndex = array_search($shipment['status'], $shipmentFlow, true);
                            $nextShipmentStatus = $shipmentFlowIndex !== false ? ($shipmentFlow[$shipmentFlowIndex + 1] ?? '') : '';
                            ?>
                            <td>
                                <?php if ($nextShipmentStatus !== ''): ?>
                                    <form method="post" action="update-shipment-status.php" class="shipment-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="tracking_no" value="<?= $h($shipment['tracking_no']) ?>">
                                        <select name="status" aria-label="Next shipment status">
                                            <option value="<?= $h($nextShipmentStatus) ?>"><?= $h($nextShipmentStatus) ?></option>
                                            <?php if (!in_array($shipment['status'], ['Delivered', 'Cancelled'], true)): ?>
                                                <option value="Cancelled">Cancelled</option>
                                            <?php endif; ?>
                                        </select>
                                        <button class="shipment-btn" type="submit">Update</button>
                                    </form>
                                <?php else: ?>
                                    <span class="shipment-note">No further status</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
