<?php
require_once __DIR__ . '/../config.php';
require_auth();
if (!is_admin()) {
    redirect('../dashboard.php');
}

$reports = $pdo->query("SELECT p.id AS parcel_id, p.assigned_rider_id, p.tracking_number, p.status, p.created_at, u.full_name AS rider_name FROM parcels p LEFT JOIN riders r ON r.id = p.assigned_rider_id LEFT JOIN users u ON u.id = r.user_id AND u.role = 'rider' ORDER BY p.created_at DESC")->fetchAll();
$selectedTracking = strtoupper(trim((string) ($_GET['tracking'] ?? '')));
$selectedReport = null;
foreach ($reports as $report) {
    if ($report['tracking_number'] === $selectedTracking) {
        $selectedReport = $report;
        break;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.report-actions { display: flex; justify-content: flex-end; margin-bottom: 16px; }
.print-report-header { display: none; }
/* ==========================================
   COURIERFLOW REPORTS PAGE DESIGN
========================================== */

/* Delivery truck and GPS background */
@media screen {
    body {
        background-image: url('../assets/images/courierflow-bg.png') !important;
        background-size: cover !important;
        background-position: center center !important;
        background-repeat: no-repeat !important;
        background-attachment: fixed !important;
    }

    /* Show the background */
    .app-shell {
        background: transparent !important;
    }

    /* Main Admin Console panel */
    .app-shell > .content {
        background: rgba(235, 245, 255, 0.88) !important;
        border: 1px solid rgba(255, 255, 255, 0.9) !important;
        border-radius: 20px !important;
        margin: 20px !important;
        padding: 20px !important;
        box-shadow: 0 12px 35px rgba(15, 23, 42, 0.12) !important;
    }

    /* Admin Console header */
    .app-shell > .content > .topbar {
        background: transparent !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        margin: 0 !important;
        padding: 8px 12px 20px !important;
    }

    /* White report cards */
    .app-shell > .content .panel.card {
        background: rgba(255, 255, 255, 0.97) !important;
        border: 1px solid #e0edff !important;
        border-radius: 16px !important;
        padding: 22px !important;
        box-shadow: 0 8px 25px rgba(15, 23, 42, 0.06);
    }

    /* Report headings */
    .app-shell > .content h1,
    .app-shell > .content h2,
    .app-shell > .content h3 {
        color: #0b2c5d;
    }
}
@media print {
    .sidebar, .topbar, .report-actions { display: none !important; }
    body, .content, .app-shell { background: #fff; display: block; padding: 0; }
    .content { width: 100%; }
    .panel { border: 0; padding: 0; margin: 0; }
    .print-report-header { display: block; margin-bottom: 24px; }
    .badge { border: 1px solid #777; background: #fff !important; color: #111 !important; }
    th, td { border-bottom: 1px solid #999; }
}
</style>
<div class="panel" id="delivery-report">
    <div class="print-report-header">
        <h1>CourierFlow Delivery Report</h1>
        <p>Generated: <?= date('Y-m-d H:i:s') ?></p>
    </div>
    <div class="report-actions">
        <button type="button" class="btn btn-primary" onclick="window.print()">Print Report</button>
    </div>
    <h2>Delivery Reports</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Tracking</th><th>Status</th><th>Assigned Rider</th><th>Created</th><th>Route History</th></tr></thead>
            <tbody>
            <?php foreach ($reports as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['tracking_number'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= get_status_badge($row['status']) ?>"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars($row['rider_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><a class="btn btn-primary" href="reports.php?tracking=<?= urlencode($row['tracking_number']) ?>">View Route</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($selectedTracking !== ''): ?>
        <div class="panel" style="margin-top: 24px;">
            <h3>Route History — <?= htmlspecialchars($selectedTracking, ENT_QUOTES, 'UTF-8') ?></h3>
            <p id="route-history-message" class="muted">Loading route history…</p>
            <div id="route-history-map" class="map" style="height: 580px !important; min-height: 580px !important;" data-tracking="<?= htmlspecialchars($selectedTracking, ENT_QUOTES, 'UTF-8') ?>" data-api-base="../api/"<?php if ($selectedReport && !empty($selectedReport['assigned_rider_id'])): ?> data-plan-url="../api/route.php?rider_id=<?= (int) $selectedReport['assigned_rider_id'] ?>&amp;parcel_id=<?= (int) $selectedReport['parcel_id'] ?>"<?php endif; ?>></div>
            <p class="muted" style="margin-top: 12px;">Share with recipient: <a href="../track.php?tracking=<?= urlencode($selectedTracking) ?>" target="_blank" rel="noopener">Open recipient tracking view</a></p>
        </div>
    <?php endif; ?>
</div>
<?php if ($selectedTracking !== ''): ?><script src="../assets/js/route-history.js"></script><?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
