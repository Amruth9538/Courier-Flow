<?php
require_once __DIR__ . '/config.php';
require_auth();
if (!is_admin()) {
    redirect('rider-dashboard.php');
}

require_once __DIR__ . '/includes/header.php';

$stats = [];
$stats['riders'] = $pdo->query("SELECT COUNT(*) AS total FROM riders r JOIN users u ON u.id = r.user_id WHERE u.role = 'rider'")->fetchColumn();
$stats['online'] = $pdo->query("SELECT COUNT(*) AS total FROM riders r JOIN users u ON u.id = r.user_id WHERE u.role = 'rider' AND r.is_online = 1")->fetchColumn();
$stats['offline'] = $pdo->query("SELECT COUNT(*) AS total FROM riders r JOIN users u ON u.id = r.user_id WHERE u.role = 'rider' AND r.is_online = 0")->fetchColumn();
$stats['parcels'] = $pdo->query('SELECT COUNT(*) AS total FROM parcels')->fetchColumn();
$stats['delivered'] = $pdo->query("SELECT COUNT(*) AS total FROM parcels WHERE status = 'Delivered'")->fetchColumn();
$stats['pending'] = $pdo->query("SELECT COUNT(*) AS total FROM parcels WHERE status = 'Pending'")->fetchColumn();

$riders = $pdo->query("SELECT r.*, u.full_name, u.email FROM riders r JOIN users u ON u.id = r.user_id WHERE u.role = 'rider' ORDER BY r.is_online DESC, u.full_name ASC")->fetchAll();
$parcels = $pdo->query('SELECT p.*, u.full_name AS rider_name FROM parcels p LEFT JOIN riders r ON r.id = p.assigned_rider_id LEFT JOIN users u ON u.id = r.user_id ORDER BY p.created_at DESC LIMIT 10')->fetchAll();
$logs = $pdo->query('SELECT a.*, u.full_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 10')->fetchAll();
?>
<style>
/* ==========================================
   COURIERFLOW ADMIN DASHBOARD DESIGN
========================================== */

/* Delivery truck and GPS background */
body {
    background-image: url('assets/images/courierflow-bg.png') !important;
    background-size: cover !important;
    background-position: center center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Allow background image to appear */
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

/* Dashboard cards */
.app-shell > .content .panel.card {
    background: rgba(255, 255, 255, 0.97) !important;
    border: 1px solid #e0edff !important;
    border-radius: 16px !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.06);
}

/* Keep dashboard text readable */
.app-shell > .content h1,
.app-shell > .content h2,
.app-shell > .content h3 {
    color: #0b2c5d;
}
</style>
<!-- Modern Admin Dashboard Statistics -->
<div class="stats-grid admin-stats-grid">

    <div class="stat-card admin-stat-card">
        <div class="admin-stat-icon">👥</div>
        <h3>Total Riders</h3>
        <p><?= (int) $stats['riders'] ?></p>
    </div>

    <div class="stat-card admin-stat-card">
        <div class="admin-stat-icon">🟢</div>
        <h3>Online Riders</h3>
        <p><?= (int) $stats['online'] ?></p>
    </div>

    <div class="stat-card admin-stat-card">
        <div class="admin-stat-icon">🔴</div>
        <h3>Offline Riders</h3>
        <p><?= (int) $stats['offline'] ?></p>
    </div>

    <div class="stat-card admin-stat-card">
        <div class="admin-stat-icon">📦</div>
        <h3>Pending Parcels</h3>
        <p><?= (int) $stats['pending'] ?></p>
    </div>

</div>
<!-- Modern Admin Live Rider Tracking -->
<div class="panel admin-map-panel">

    <div class="admin-map-header">
        <div>
            <h2>📍 Live Rider Tracking</h2>
            <p>Monitor the latest GPS locations of your online delivery riders.</p>
        </div>

        <span class="admin-live-badge">● Live Tracking</span>
    </div>

    <div id="map" class="map admin-modern-map"></div>

    <div id="tracking-summary"
         class="tracking-summary"
         aria-live="polite">
    </div>

</div>
<div class="panel">
    <h2>Recent Parcels</h2>
    <div class="table-wrap">
        <table class="admin-modern-table">
            <thead><tr><th>Tracking</th><th>Recipient</th><th>Assigned Rider</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($parcels as $parcel): ?>
                <tr>
                    <td><?= htmlspecialchars($parcel['tracking_number'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($parcel['recipient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($parcel['rider_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="badge <?= get_status_badge($parcel['status']) ?>"><?= htmlspecialchars($parcel['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars($parcel['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Modern Admin Recent Activity -->
<div class="panel admin-activity-panel">

    <h2>📋 Recent Activity</h2>
    <p class="muted">Latest activities recorded in the delivery system.</p>

    <div class="admin-activity-list">

        <?php if (empty($logs)): ?>
            <p class="muted">No recent activities available.</p>
        <?php else: ?>

            <?php foreach ($logs as $log): ?>

                <div class="admin-activity-item">

                    <div class="admin-activity-icon">🔔</div>

                    <div class="admin-activity-content">

                        <h3>
                            <?= htmlspecialchars($log['full_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?>
                        </h3>

                        <p class="admin-activity-action">
                            <?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <p class="admin-activity-details">
                            <?= htmlspecialchars($log['details'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <small>
                            <?= htmlspecialchars($log['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </small>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>
<style>
/* Modern Admin Statistics Cards */

.admin-stats-grid {
    margin-bottom: 25px;
}

.admin-stat-card {
    background: linear-gradient(135deg, #ffffff, #eff6ff);
    border: 1px solid #dbeafe;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.admin-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(37, 99, 235, 0.12);
}

.admin-stat-icon {
    font-size: 28px;
    margin-bottom: 12px;
}

.admin-stat-card h3 {
    color: #64748b;
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 10px;
}

.admin-stat-card p {
    color: #1d4ed8;
    font-size: 32px;
    font-weight: 800;
    margin: 0;
}
/* Modern Admin Live Tracking Map */

.admin-map-panel {
    padding: 24px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
}

.admin-map-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}

.admin-map-header h2 {
    margin: 0 0 8px;
    color: #0f172a;
    font-size: 21px;
    font-weight: 700;
}

.admin-map-header p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}

.admin-live-badge {
    background: #dcfce7;
    color: #166534;
    padding: 9px 15px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

#map.admin-modern-map {
    width: 100%;
    height: 480px !important;
    min-height: 480px !important;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

@media (max-width: 768px) {
    .admin-map-panel {
        padding: 15px;
    }

    #map.admin-modern-map {
        height: 320px !important;
        min-height: 320px !important;
    }

    .admin-map-header h2 {
        font-size: 18px;
    }
}
/* Modern Admin Recent Parcels Table */

.admin-modern-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #ffffff;
    border-radius: 12px;
    overflow: hidden;
}

.admin-modern-table thead {
    background: #eff6ff;
}

.admin-modern-table th {
    padding: 16px;
    color: #1e40af;
    font-size: 13px;
    font-weight: 700;
    text-align: left;
}

.admin-modern-table td {
    padding: 15px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
    color: #334155;
}

.admin-modern-table tbody tr:hover {
    background: #f8fafc;
}

.admin-modern-table tbody tr:last-child td {
    border-bottom: none;
}

@media (max-width: 768px) {
    .admin-modern-table {
        min-width: 650px;
    }

    .table-wrap {
        overflow-x: auto;
    }
}
/* Modern Admin Recent Activity */

.admin-activity-panel {
    padding: 24px;
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
}

.admin-activity-panel h2 {
    margin: 0 0 8px;
    color: #0f172a;
    font-size: 21px;
}

.admin-activity-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 20px;
}

.admin-activity-item {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    padding: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    transition: 0.2s ease;
}

.admin-activity-item:hover {
    background: #eff6ff;
    transform: translateX(3px);
}

.admin-activity-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    background: #dbeafe;
    border-radius: 12px;
    font-size: 20px;
}

.admin-activity-content {
    flex: 1;
    min-width: 0;
}

.admin-activity-content h3 {
    margin: 0 0 5px;
    color: #0f172a;
    font-size: 15px;
}

.admin-activity-action {
    margin: 0 0 5px;
    color: #2563eb;
    font-size: 13px;
    font-weight: 600;
}

.admin-activity-details {
    margin: 0 0 8px;
    color: #475569;
    font-size: 13px;
    overflow-wrap: anywhere;
}

.admin-activity-content small {
    color: #94a3b8;
    font-size: 12px;
}

@media (max-width: 768px) {
    .admin-activity-panel {
        padding: 15px;
    }

    .admin-activity-item {
        padding: 12px;
    }
}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
