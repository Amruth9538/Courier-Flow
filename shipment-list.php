<?php
require_once __DIR__ . '/config.php';
require_auth();
$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}

$search = substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
$status = trim((string) ($_GET['status'] ?? ''));
$statuses = ['Booked', 'Picked Up', 'In Transit', 'Out for Delivery', 'Delivered', 'Cancelled'];
$shipmentSchemaReady = (bool) $pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn();
$where = [];
$params = [];
if ($role !== 'admin') {
    $where[] = 'user_id = ?';
    $params[] = (int) $_SESSION['user_id'];
}
if ($search !== '') {
    $where[] = '(tracking_no LIKE ? OR receiver_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if (in_array($status, $statuses, true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}
$shipments = [];
if ($shipmentSchemaReady) {
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
?>
<?php
$bodyAttributes = 'class="shipment-page shipment-app-page"';
require_once __DIR__ . '/includes/header.php';
?>
<div class="shipment-shell">
    <header class="shipment-header">
        <div><h1>Shipment List</h1><p class="shipment-muted">Search and manage booked shipments.</p></div>
        <div class="shipment-buttons">
            <a class="shipment-btn" href="create-shipment.php">Create Shipment</a>
            <a class="shipment-btn secondary" href="<?= $role === 'admin' ? 'dashboard.php' : 'customer-dashboard.php' ?>">Dashboard</a>
        </div>
    </header>
    <section class="shipment-section">
        <?php if (!$shipmentSchemaReady): ?>
            <div class="shipment-error">Shipment tables are not set up yet. Import <code>database_shipments.sql</code> into the parcel_delivery_system database, then reload this page.</div>
        <?php endif; ?>
        <form method="get" class="shipment-fields">
            <label class="shipment-field">Tracking or receiver<input name="search" value="<?= $h($search) ?>" maxlength="100" placeholder="Search shipments"></label>
            <label class="shipment-field">Status<select name="status"><option value="">All statuses</option>
                <?php foreach ($statuses as $shipmentStatus): ?><option value="<?= $h($shipmentStatus) ?>" <?= $status === $shipmentStatus ? 'selected' : '' ?>><?= $h($shipmentStatus) ?></option><?php endforeach; ?>
            </select></label>
            <div class="shipment-buttons"><button class="shipment-btn" type="submit">Search</button><a class="shipment-btn secondary" href="shipment-list.php">Clear</a></div>
        </form>
        <div class="shipment-table-wrap" style="margin-top:20px">
            <table class="shipment-table">
                <thead><tr><th>Tracking</th><th>Status</th><th>Recipient</th><th>Destination</th><th>Pieces</th><th>Chargeable wt.</th><th>Value</th><th>Created</th><th>Invoice</th></tr></thead>
                <tbody>
                <?php if (!$shipments): ?><tr><td colspan="9">No shipments found.</td></tr><?php endif; ?>
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
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
