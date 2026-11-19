<?php
require_once __DIR__ . '/config.php';
require_auth();

if (is_admin()) {
    redirect('dashboard.php');
}

$rider = ensure_rider_profile();
if (!$rider) {
    redirect('login.php');
}

$rider_id = $rider['id'];
$message = '';
$error = '';

// Handle rider Online / Offline status
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'toggle_online_status') {

    try {
        verify_csrf();

        $pdo->beginTransaction();

        // Lock this rider while updating availability
        $rider_stmt = $pdo->prepare("
            SELECT is_online
            FROM riders
            WHERE id = ?
            FOR UPDATE
        ");

        $rider_stmt->execute([$rider_id]);
        $current_online = $rider_stmt->fetchColumn();

        if ($current_online === false) {
            throw new RuntimeException('Rider not found.');
        }

        // Switch Online to Offline, or Offline to Online
        $next_status = ((int)$current_online === 1) ? 0 : 1;

        // Count unfinished deliveries
        $active_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM parcels
            WHERE assigned_rider_id = ?
              AND status NOT IN ('Delivered', 'Cancelled')
        ");

        $active_stmt->execute([$rider_id]);
        $active_count = (int)$active_stmt->fetchColumn();

        // Available only if Online and no active deliveries
        $is_available = (
            $next_status === 1 && $active_count === 0
        ) ? 1 : 0;

        // Update Online and Available status
        $update_rider = $pdo->prepare("
            UPDATE riders
            SET is_online = ?,
                is_available = ?
            WHERE id = ?
        ");

        $update_rider->execute([
            $next_status,
            $is_available,
            $rider_id
        ]);

        // Assign one waiting parcel if rider is available
        if ($is_available === 1) {

            $waiting_stmt = $pdo->prepare("
                SELECT id
                FROM parcels
                WHERE assigned_rider_id IS NULL
                  AND status = 'Pending'
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");

            $waiting_stmt->execute();
            $waiting_parcel_id = $waiting_stmt->fetchColumn();

            if ($waiting_parcel_id) {

                $assign_stmt = $pdo->prepare("
                    UPDATE parcels
                    SET assigned_rider_id = ?
                    WHERE id = ?
                      AND assigned_rider_id IS NULL
                      AND status = 'Pending'
                ");

                $assign_stmt->execute([
                    $rider_id,
                    $waiting_parcel_id
                ]);

                // Mark rider Busy after successful assignment
                if ($assign_stmt->rowCount() > 0) {

                    $busy_stmt = $pdo->prepare("
                        UPDATE riders
                        SET is_available = 0
                        WHERE id = ?
                    ");

                    $busy_stmt->execute([$rider_id]);
                    // Notify customer when a waiting parcel gets assigned
$waiting_customer_stmt = $pdo->prepare("
    SELECT customer_id, tracking_number
    FROM parcels
    WHERE id = ?
");

$waiting_customer_stmt->execute([$waiting_parcel_id]);

$waiting_parcel = $waiting_customer_stmt->fetch(PDO::FETCH_ASSOC);

// Only registered customers receive notifications
if ($waiting_parcel && !empty($waiting_parcel['customer_id'])) {

    $waiting_notification_stmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id,
            parcel_id,
            title,
            message,
            is_read
        )
        VALUES (?, ?, ?, ?, 0)
    ");

    $waiting_notification_stmt->execute([
        (int) $waiting_parcel['customer_id'],
        (int) $waiting_parcel_id,
        'Rider Assigned',
        'Your parcel ' . $waiting_parcel['tracking_number'] .
        ' has been assigned to a delivery rider.'
    ]);
}
                }
            }
        }

        $pdo->commit();

        // Update current rider information
        $rider['is_online'] = $next_status;

        $message = $next_status
            ? 'You are now online.'
            : 'You are now offline.';

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = 'Unable to update your online status. Please try again.';
    }
}

// 处理包裹状态更新与凭证上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_parcel') {
    verify_csrf();

    $parcel_id = (int)($_POST['parcel_id'] ?? 0);
    $status    = sanitize_string($_POST['status'] ?? '');
    $remarks   = sanitize_string($_POST['remarks'] ?? '');

    // 验证包裹归属
    $stmt = $pdo->prepare('SELECT id FROM parcels WHERE id = ? AND assigned_rider_id = ?');
    $stmt->execute([$parcel_id, $rider_id]);

    if ($stmt->fetch()) {
        try {
            $pdo->beginTransaction();

            $photo_path = null;
            if (isset($_FILES['delivery_photo']) && $_FILES['delivery_p
            hoto']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/uploads/proofs/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $ext = strtolower(pathinfo($_FILES['delivery_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['delivery_photo']['tmp_name'], $upload_dir . $filename)) {
                        $photo_path = 'uploads/proofs/' . $filename;

                        $stmt_photo = $pdo->prepare("INSERT INTO delivery_photos (parcel_id, rider_id, file_path) VALUES (?, ?, ?)");
                        $stmt_photo->execute([$parcel_id, $rider_id, $photo_path]);
                    }
                }
            }

            // 更新包裹状态
            // Get the parcel's current status before updating it
$old_status_stmt = $pdo->prepare("
    SELECT status
    FROM parcels
    WHERE id = ?
      AND assigned_rider_id = ?
    FOR UPDATE
");

$old_status_stmt->execute([$parcel_id, $rider_id]);

$old_status = $old_status_stmt->fetchColumn();

// Update parcel status
$pdo->prepare('UPDATE parcels SET status = ? WHERE id = ?')
    ->execute([$status, $parcel_id]);

// Check whether this rider has any active shipments
$active_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM parcels
    WHERE assigned_rider_id = ?
      AND status NOT IN ('Delivered', 'Cancelled')
");

$active_stmt->execute([$rider_id]);

$active_count = (int)$active_stmt->fetchColumn();

// Rider is available only when no active shipments remain
$is_available = ($active_count === 0) ? 1 : 0;

// Update rider availability
$availability_stmt = $pdo->prepare("
    UPDATE riders
    SET is_available = ?
    WHERE id = ?
");

$availability_stmt->execute([$is_available, $rider_id]);
// Automatically assign a waiting parcel when rider becomes available
if ($is_available === 1 && !empty($rider['is_online'])) {

    // Find the oldest waiting parcel.
    // FOR UPDATE locks the selected parcel during this transaction.
    $waiting_stmt = $pdo->prepare("
        SELECT id
        FROM parcels
        WHERE assigned_rider_id IS NULL
          AND status = 'Pending'
        ORDER BY created_at ASC, id ASC
        LIMIT 1
        FOR UPDATE
    ");

    $waiting_stmt->execute();

    $waiting_parcel_id = $waiting_stmt->fetchColumn();

    if ($waiting_parcel_id) {

        // Assign the waiting parcel to this rider
        $assign_stmt = $pdo->prepare("
            UPDATE parcels
            SET assigned_rider_id = ?
            WHERE id = ?
              AND assigned_rider_id IS NULL
              AND status = 'Pending'
        ");

        $assign_stmt->execute([
            $rider_id,
            $waiting_parcel_id
        ]);

        // Mark the rider Busy only after successful assignment
        if ($assign_stmt->rowCount() > 0) {

            $busy_stmt = $pdo->prepare("
                UPDATE riders
                SET is_available = 0
                WHERE id = ?
            ");

            $busy_stmt->execute([$rider_id]);
        }
    }
}

// 记录状态变更历史
            $stmt_hist = $pdo->prepare('INSERT INTO parcel_status_history (parcel_id, status, remarks, photo_path, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt_hist->execute([$parcel_id, $status, $remarks, $photo_path, $_SESSION['user_id']]);
            // ==========================================
// AUTOMATIC CUSTOMER STATUS NOTIFICATIONS
// ==========================================

// Find the customer and tracking number for this parcel
$customer_stmt = $pdo->prepare("
    SELECT customer_id, tracking_number
    FROM parcels
    WHERE id = ?
");

$customer_stmt->execute([$parcel_id]);
$parcel_info = $customer_stmt->fetch(PDO::FETCH_ASSOC);

// Only registered customers receive website notifications
if (
    $parcel_info &&
    !empty($parcel_info['customer_id']) &&
    $old_status !== false &&
    $old_status !== $status
) {

    $customer_id = (int) $parcel_info['customer_id'];
    $tracking_number = $parcel_info['tracking_number'];

    $notification_title = '';
    $notification_message = '';

    if ($status === 'In Transit') {

        $notification_title = 'Parcel In Transit';

        $notification_message =
            'Your parcel ' . $tracking_number .
            ' is on the way to its destination.';

    } elseif ($status === 'Delivered') {

        $notification_title = 'Parcel Delivered';

        $notification_message =
            'Your parcel ' . $tracking_number .
            ' has been delivered successfully.';

    } elseif ($status === 'Cancelled') {

        $notification_title = 'Parcel Cancelled';

        $notification_message =
            'Your parcel ' . $tracking_number .
            ' has been cancelled.';
    }

    if ($notification_title !== '') {

        $notification_stmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id,
                parcel_id,
                title,
                message,
                is_read
            )
            VALUES (?, ?, ?, ?, 0)
        ");

        $notification_stmt->execute([
            $customer_id,
            $parcel_id,
            $notification_title,
            $notification_message
        ]);
    }
}

            $pdo->commit();
            $message = 'Parcel status updated successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to update status: ' . $e->getMessage();
        }
    } else {
        $error = 'Unauthorized parcel action.';
    }
}

// 获取分配给该 Rider 的包裹
$parcels = $pdo->prepare('SELECT * FROM parcels WHERE assigned_rider_id = ? ORDER BY created_at DESC');
$parcels->execute([$rider_id]);
$assigned_parcels = $parcels->fetchAll();

// Rider Dashboard Statistics
$total_deliveries = count($assigned_parcels);

$pending_deliveries = 0;
$transit_deliveries = 0;
$delivered_deliveries = 0;

foreach ($assigned_parcels as $delivery) {

    if ($delivery['status'] === 'Pending') {
        $pending_deliveries++;
    }

    if ($delivery['status'] === 'In Transit') {
        $transit_deliveries++;
    }

    if ($delivery['status'] === 'Delivered') {
        $delivered_deliveries++;
    }
}

$active_parcel = null;
foreach ($assigned_parcels as $parcel) {
    if (!in_array($parcel['status'], ['Delivered', 'Cancelled'], true)) {
        $active_parcel = $parcel;
        break;
    }
}

$bodyAttributes = 'data-rider-tracking="true" data-rider-id="' . (int) $rider_id . '" data-parcel-id="' . (int) ($active_parcel['id'] ?? 0) . '" data-rider-online="' . (!empty($rider['is_online']) ? 'true' : 'false') . '"';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    body {
    outline: 5px solid #00ff00 !important;
}
/* =====================================
   COURIERFLOW RIDER DASHBOARD DESIGN
===================================== */

/* Delivery truck background */
/* CourierFlow 2030 Premium Background */
body {
    background:
        radial-gradient(
            circle at 85% 15%,
            rgba(59, 130, 246, 0.22),
            transparent 35%
        ),
        radial-gradient(
            circle at 15% 85%,
            rgba(6, 182, 212, 0.18),
            transparent 40%
        ),
        linear-gradient(
            135deg,
            #f8fbff 0%,
            #eaf3ff 45%,
            #dbeafe 100%
        ) !important;

    background-attachment: fixed !important;
}

/* Transparent application shell */
.app-shell {
    background: transparent !important;
}

/* Main dashboard panel */
.app-shell > .content {
    background: rgba(235, 245, 255, 0.90) !important;
    border: 1px solid rgba(255, 255, 255, 0.9) !important;
    border-radius: 20px !important;
    margin: 20px !important;
    padding: 20px !important;
    box-shadow: 0 12px 35px rgba(15, 23, 42, 0.12) !important;
}

/* Dashboard top header */
.app-shell > .content > .topbar {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}

/* Rider dashboard statistics */
.main-content .stats-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px !important;
    margin-bottom: 25px !important;
}

/* Modern statistics cards */
.main-content .stat-card {
    background: rgba(255, 255, 255, 0.97) !important;
    border: 1px solid #dbeafe !important;
    border-radius: 18px !important;
    padding: 24px 18px !important;
    text-align: center !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.08) !important;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

/* Hover animation */
.main-content .stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 30px rgba(37, 99, 235, 0.15) !important;
}

/* Statistics headings */
.main-content .stat-card h3 {
    font-size: 16px !important;
    font-weight: 600 !important;
    color: #334155 !important;
    margin-bottom: 14px !important;
}

/* Statistics numbers */
.main-content .stat-card p {
    font-size: 34px !important;
    font-weight: 800 !important;
    color: #1d4ed8 !important;
    margin: 0 !important;
}

/* Responsive design */
@media (max-width: 1000px) {
    .main-content .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .main-content .stats-grid {
        grid-template-columns: 1fr;
    }

    .app-shell > .content {
        margin: 10px !important;
        padding: 15px !important;
    }
}
/* =========================================
   COURIERFLOW 2030 - SMART GPS NAVIGATION
========================================= */

/* Main GPS tracking panel */
.main-content .rider-map-panel {
    background: linear-gradient(
        135deg,
        rgba(10, 30, 55, 0.98),
        rgba(7, 20, 38, 0.98)
    ) !important;

    border: 1px solid rgba(0, 217, 255, 0.4) !important;
    border-radius: 24px !important;
    padding: 25px !important;
    margin: 25px 0 !important;

    box-shadow:
        0 0 30px rgba(0, 217, 255, 0.12),
        0 15px 40px rgba(0, 0, 0, 0.25) !important;
}

/* GPS panel header */
.main-content .rider-map-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 22px;
}

/* GPS heading */
.main-content .rider-map-header h2 {
    color: #67e8f9 !important;
    font-size: 23px !important;
    font-weight: 800 !important;
    margin: 0 0 10px !important;
}

/* GPS description */
.main-content .rider-map-header p {
    color: #94a3b8 !important;
    font-size: 14px !important;
    line-height: 1.6;
    margin: 0 !important;
}

/* Glowing GPS status badge */
.main-content .rider-map-badge {
    display: inline-flex;
    align-items: center;

    background: rgba(16, 185, 129, 0.12) !important;
    color: #4ade80 !important;

    border: 1px solid rgba(74, 222, 128, 0.4) !important;
    border-radius: 50px !important;

    padding: 10px 18px !important;
    font-size: 13px !important;
    font-weight: 700 !important;

    box-shadow: 0 0 18px rgba(16, 185, 129, 0.15);
}

/* Live GPS map */
.main-content #rider-map {
    width: 100% !important;
    height: 420px !important;
    min-height: 350px !important;

    border-radius: 18px !important;
    border: 2px solid rgba(34, 211, 238, 0.45) !important;

    overflow: hidden !important;

    box-shadow:
        0 0 25px rgba(0, 217, 255, 0.15),
        0 12px 30px rgba(0, 0, 0, 0.2) !important;
}

/* Tracking summary */
.main-content #rider-tracking-summary {
    background: rgba(15, 35, 60, 0.95) !important;
    color: #e2e8f0 !important;

    border: 1px solid rgba(0, 217, 255, 0.2) !important;
    border-radius: 15px !important;

    padding: 18px !important;
    margin-top: 20px !important;

    font-size: 14px !important;
    line-height: 1.7 !important;
}

/* Mobile responsive */
@media (max-width: 768px) {

    .main-content .rider-map-panel {
        padding: 16px !important;
    }

    .main-content .rider-map-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .main-content #rider-map {
        height: 320px !important;
        min-height: 300px !important;
    }
}
</style>

<div class="main-content">
    <style>
.main-content {
    background:
    radial-gradient(circle at top right, rgba(0, 217, 255, 0.12), transparent 40%),
    linear-gradient(135deg, #071426, #10243d) !important;
    border: 1px solid rgba(0, 217, 255, 0.35) !important;
box-shadow:
    0 0 30px rgba(0, 217, 255, 0.12),
    inset 0 0 25px rgba(0, 217, 255, 0.04) !important;
    border-radius: 25px !important;
    padding: 25px !important;
}

.main-content .stat-card {
    background: #102a43 !important;
    color: white !important;
    border: 2px solid #00d9ff !important;
    border-radius: 20px !important;
}

.main-content .stat-card h3 {
    color: #ffffff !important;
}

.main-content .stat-card p {
    color: #00d9ff !important;
}
</style>
    <!-- Rider Dashboard Statistics -->
<div class="stats-grid">

    <div class="stat-card">
        <h3>📦 Total Deliveries</h3>
        <p><?= $total_deliveries ?></p>
    </div>

    <div class="stat-card">
        <h3>⏳ Pending</h3>
        <p><?= $pending_deliveries ?></p>
    </div>

    <div class="stat-card">
        <h3>🚚 In Transit</h3>
        <p><?= $transit_deliveries ?></p>
    </div>

    <div class="stat-card">
        <h3>✅ Delivered</h3>
        <p><?= $delivered_deliveries ?></p>
    </div>

</div>
    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Modern Rider Availability Card -->
<div class="panel rider-availability-card" style="
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    padding: 25px;
    border-radius: 15px;
    background: linear-gradient(135deg, #ffffff, #eef6ff);
    border: 1px solid #dbeafe;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
">

    <div>
        <h2 style="margin: 0 0 10px; color: #0f172a;">
            🚴 Rider Availability
        </h2>

        <p style="margin: 0; color: #64748b;">
            Current Status:
            <strong style="
                color: <?= !empty($rider['is_online']) ? '#20bd11' : '#f00606' ?>;
                font-size: 16px;
            ">
                <?= !empty($rider['is_online']) ? '🟢 Online' : '🔴 Offline' ?>
            </strong>
        </p>
    </div>

    <form method="POST" action="rider-dashboard.php">
        <?= csrf_field() ?>

        <input type="hidden"
               name="action"
               value="toggle_online_status">

        <button type="submit"
                class="btn <?= !empty($rider['is_online']) ? 'btn-danger' : 'btn-success' ?>"
                style="
                    padding: 12px 22px;
                    border-radius: 10px;
                    font-weight: 600;
                    font-size: 14px;
                ">

            <?= !empty($rider['is_online'])
                ? '🔴 Go Offline'
                : '🟢 Go Online' ?>

        </button>
    </form>

</div>>

    <!-- Modern Rider Live Tracking Map -->
<div class="panel rider-map-panel">

    <div class="rider-map-header">
        <div>
            <h2>📍 My Live Location &amp; Delivery Route</h2>
            <p>
                Track your current GPS location and view
                the route to your active delivery address.
            </p>
        </div>

        <span class="rider-map-badge">
            ● GPS Tracking
        </span>
    </div>

    <div id="rider-map" class="map rider-modern-map"></div>

    <div id="rider-tracking-summary"
         class="tracking-summary"
         aria-live="polite">
    </div>

</div>

    <!-- 配送任务列表卡片 -->
    <div class="panel card delivery-mission-panel">
        <h2 class="card-title" style="font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 20px;">My Assigned Deliveries</h2>
        <table class="data-table modern-delivery-table">
            <thead>
                <tr>
                    <th>Tracking #</th>
                    <th>Recipient</th>
                    <th>Delivery Address</th>
                    <th>Status</th>
                    <th style="text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assigned_parcels)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 24px; color: #94a3b8;">
                            No delivery tasks assigned to you yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assigned_parcels as $p): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['tracking_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($p['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small style="color: #64748b;"><?= htmlspecialchars($p['recipient_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td style="font-size: 13px; color: #0f172a;">
                                📍 <?= htmlspecialchars($p['recipient_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?php $status_slug = strtolower(str_replace(' ', '_', $p['status'] ?? 'pending')); ?>
                                <span class="badge badge-<?= $status_slug ?>">
                                    <?= htmlspecialchars($p['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <button type="button"
                                        class="btn btn-primary btn-update-status"
                                        data-parcel-id="<?= $p['id'] ?>"
                                        data-tracking="<?= htmlspecialchars($p['tracking_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-status="<?= htmlspecialchars($p['status'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    🔄 Update Status
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 修改状态 Modal 弹窗 -->
<div id="statusModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center;">
    <div style="background: #ffffff; padding: 28px; border-radius: 12px; width: 90%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0; font-size: 18px; color: #0f172a; margin-bottom: 16px;">
            Update Delivery Status (<span id="modal_tracking_no"></span>)
        </h3>

        <form action="rider-dashboard.php" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_parcel">
            <input type="hidden" id="modal_parcel_id" name="parcel_id">

            <div style="margin-bottom: 16px;">
                <label class="form-label">Parcel Status</label>
                <select id="modal_status" name="status" class="form-select" required style="width:100%; padding: 8px 12px; margin-top: 4px;">
                    <option value="Pending">Pending</option>
                    <option value="In Transit">In Transit</option>
                    <option value="Delivered">Delivered</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="form-label">Proof of Delivery Photo (Optional)</label>
                <input type="file" name="delivery_photo" class="form-input" accept="image/*" style="width:100%; margin-top: 4px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label class="form-label">Remarks / Notes</label>
                <textarea name="remarks" class="form-textarea" rows="3" placeholder="Add optional delivery notes" style="width:100%; margin-top: 4px;"></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" id="btn-close-status-modal" class="btn" style="background: #64748b; color: #fff;">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Update</button>
            </div>
        </form>
    </div>
</div>
/* ======================================
   COURIERFLOW 2030 RIDER STATUS
====================================== */

.main-content .rider-availability-card {
    background: linear-gradient(135deg, #102a43, #071426) !important;
    border: 1px solid rgba(0, 217, 255, 0.4) !important;
    border-radius: 22px !important;
    padding: 28px !important;
    margin: 25px 0 !important;
    box-shadow: 0 0 25px rgba(0, 217, 255, 0.12) !important;
}

/* Futuristic heading */
.main-content .rider-availability-card h2 {
    color: #67e8f9 !important;
    font-size: 23px !important;
    font-weight: 800 !important;
}

/* Status description */
.main-content .rider-availability-card p {
    color: #cbd5e1 !important;
    font-size: 15px !important;
}

/* Glowing status indicator */
.main-content .rider-availability-card strong {
    display: inline-block;
    padding: 8px 15px;
    border-radius: 30px;
    background: rgba(255, 255, 255, 0.08);
    font-weight: 700 !important;
}

/* Online / Offline button */
.main-content .rider-availability-card button[type="submit"] {
    border: none !important;
    border-radius: 50px !important;
    padding: 15px 30px !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    cursor: pointer;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

/* Green: Go Online */
.main-content .rider-availability-card .btn-success {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    color: white !important;
    box-shadow: 0 0 20px rgba(16, 185, 129, 0.35);
}

/* Red: Go Offline */
.main-content .rider-availability-card .btn-danger {
    background: linear-gradient(135deg, #ef4444, #b91c1c) !important;
    color: white !important;
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.35);
}

/* Button animation */
.main-content .rider-availability-card button:hover {
    transform: translateY(-3px);
}

/* Mobile layout */
@media (max-width: 600px) {
    .main-content .rider-availability-card {
        flex-direction: column;
        align-items: flex-start;
    }
}

<style>
    <style>
/* ==========================================
   COURIERFLOW 2030 — RIDER COMMAND CENTER
========================================== */

body {
    background:
        radial-gradient(circle at 80% 10%, rgba(0, 212, 255, .18), transparent 35%),
        radial-gradient(circle at 15% 80%, rgba(91, 33, 182, .18), transparent 40%),
        #071426 !important;
    background-attachment: fixed !important;
    color: #e2e8f0;
}

.app-shell {
    background: transparent !important;
}

/* Main dashboard */
.app-shell > .content {
    background: rgba(8, 20, 40, .88) !important;
    border: 1px solid rgba(56, 189, 248, .20) !important;
    border-radius: 24px !important;
    margin: 20px !important;
    padding: 25px !important;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .35) !important;
}

/* Header */
.app-shell > .content > .topbar {
    background: rgba(15, 32, 56, .85) !important;
    border: 1px solid rgba(56, 189, 248, .15) !important;
    border-radius: 18px !important;
    padding: 18px !important;
    margin-bottom: 25px !important;
    color: #e2e8f0 !important;
}

/* Statistics layout */
.main-content .stats-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px !important;
    margin-bottom: 30px !important;
}

/* Futuristic statistic cards */
.main-content .stat-card {
    position: relative;
    overflow: hidden;
    background: linear-gradient(145deg, #122945, #0b1b30) !important;
    border: 1px solid rgba(56, 189, 248, .25) !important;
    border-radius: 20px !important;
    padding: 28px 20px !important;
    text-align: left !important;
    box-shadow: 0 12px 30px rgba(0, 0, 0, .20) !important;
    transition: transform .3s ease, border-color .3s ease;
}

.main-content .stat-card::before {
    content: "";
    position: absolute;
    width: 100px;
    height: 100px;
    right: -35px;
    top: -35px;
    border-radius: 50%;
    background: rgba(0, 212, 255, .10);
    filter: blur(12px);
}

.main-content .stat-card:hover {
    transform: translateY(-6px);
    border-color: #22d3ee !important;
}

.main-content .stat-card h3 {
    color: #94a3b8 !important;
    font-size: 15px !important;
    font-weight: 600 !important;
    margin-bottom: 18px !important;
}

.main-content .stat-card p {
    color: #67e8f9 !important;
    font-size: 42px !important;
    font-weight: 800 !important;
    margin: 0 !important;
    text-shadow: 0 0 20px rgba(34, 211, 238, .25);
}

/* Different accent for each statistic */
.main-content .stat-card:nth-child(2) p {
    color: #fbbf24 !important;
}

.main-content .stat-card:nth-child(3) p {
    color: #a78bfa !important;
}

.main-content .stat-card:nth-child(4) p {
    color: #4ade80 !important;
}

/* Rider availability */
.main-content .rider-availability-card {
    background: linear-gradient(135deg, #112b46, #0a1b30) !important;
    border: 1px solid rgba(34, 211, 238, .35) !important;
    border-radius: 22px !important;
    padding: 28px !important;
    margin-bottom: 25px !important;
    box-shadow: 0 15px 35px rgba(0, 0, 0, .20) !important;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}

/* Availability heading */
.rider-availability-card h2 {
    color: #67e8f9 !important;
    font-size: 23px !important;
    font-weight: 800 !important;
}

/* Availability description */
.rider-availability-card p {
    color: #cbd5e1 !important;
}

/* Online/Offline button */
.rider-availability-card button[type="submit"] {
    border: none !important;
    border-radius: 50px !important;
    padding: 15px 30px !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    transition: transform .3s ease, box-shadow .3s ease;
}

.rider-availability-card .btn-success {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    color: white !important;
    box-shadow: 0 0 20px rgba(16, 185, 129, .25);
}

.rider-availability-card .btn-danger {
    background: linear-gradient(135deg, #ef4444, #b91c1c) !important;
    color: white !important;
    box-shadow: 0 0 20px rgba(239, 68, 68, .25);
}

.rider-availability-card button[type="submit"]:hover {
    transform: translateY(-3px);
}

/* Responsive */
@media (max-width: 1000px) {
    .main-content .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .main-content .stats-grid {
        grid-template-columns: 1fr;
    }

    .main-content .rider-availability-card {
        flex-direction: column;
        align-items: flex-start;
    }

    .app-shell > .content {
        margin: 10px !important;
        padding: 15px !important;
    }
}
/* COURIERFLOW 2030 - DELIVERY MISSION PANEL */

.main-content .delivery-mission-panel {
    background: linear-gradient(
        135deg,
        #102a43,
        #071426
    ) !important;

    border: 1px solid rgba(0, 217, 255, 0.35) !important;
    border-radius: 24px !important;
    padding: 25px !important;
    margin: 25px 0 !important;

    box-shadow:
        0 0 30px rgba(0, 217, 255, 0.10),
        0 15px 40px rgba(0, 0, 0, 0.20) !important;
}

/* Delivery section heading */
.main-content .delivery-mission-panel .card-title {
    color: #67e8f9 !important;
    font-size: 23px !important;
    font-weight: 800 !important;
    margin-bottom: 25px !important;
}

/* Keep table readable */
.main-content .delivery-mission-panel {
    overflow-x: auto;
}

</style>
/* Modern Rider Delivery Table */

.modern-delivery-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: white;
    border-radius: 12px;
    overflow: hidden;
}

.modern-delivery-table thead {
    background: #eff6ff;
}

.modern-delivery-table th {
    padding: 16px;
    color: #1e40af;
    font-size: 13px;
    font-weight: 700;
    text-align: left;
}

.modern-delivery-table td {
    padding: 15px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}

.modern-delivery-table tbody tr:hover {
    background: #f8fafc;
    transition: 0.2s;
}

.modern-delivery-table .btn-update-status {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: white;
    border: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.modern-delivery-table .btn-update-status:hover {
    background: #1e40af;
    transform: translateY(-2px);
}

@media (max-width: 768px) {
    .modern-delivery-table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
}
/* Modern Rider Tracking Map */

.rider-map-panel {
    padding: 24px;
    border-radius: 16px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
}

.rider-map-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 18px;
}

.rider-map-header h2 {
    margin: 0 0 8px;
    color: #0f172a;
    font-size: 20px;
    font-weight: 700;
}

.rider-map-header p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}

.rider-map-badge {
    background: #dcfce7;
    color: #166534;
    padding: 9px 15px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

#rider-map.rider-modern-map {
    height: 480px !important;
    min-height: 480px !important;
    width: 100%;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    overflow: hidden;
}

@media (max-width: 768px) {
    .rider-map-panel {
        padding: 15px;
    }

    #rider-map.rider-modern-map {
        height: 320px !important;
        min-height: 320px !important;
    }

    .rider-map-header h2 {
        font-size: 17px;
    }
}
</style>
<script src="assets/js/rider-tracking.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusModal = document.getElementById('statusModal');
    const btnCloseModal = document.getElementById('btn-close-status-modal');

    document.querySelectorAll('.btn-update-status').forEach(button => {
        button.addEventListener('click', function () {
            document.getElementById('modal_parcel_id').value = this.dataset.parcelId;
            document.getElementById('modal_tracking_no').textContent = this.dataset.tracking;
            document.getElementById('modal_status').value = this.dataset.status;

            statusModal.style.display = 'flex';
        });
    });

    if (btnCloseModal) {
        btnCloseModal.addEventListener('click', function () {
            statusModal.style.display = 'none';
        });
    }

    window.addEventListener('click', function (e) {
        if (e.target === statusModal) {
            statusModal.style.display = 'none';
        }
    });
});
</script>
<style>
/* =====================================
   COURIERFLOW RIDER AVAILABILITY DESIGN
===================================== */

/* Rider availability section */
.main-content .rider-availability-card {
    background: rgba(255, 255, 255, 0.97) !important;
    border: 1px solid #dbeafe !important;
    border-radius: 18px !important;
    padding: 25px !important;
    margin-bottom: 25px !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.08) !important;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
}

/* Availability heading */
.rider-availability-card h2 {
    color: #123b79 !important;
    font-size: 21px !important;
    font-weight: 700 !important;
    margin-bottom: 12px !important;
}

/* Online / Offline status */
.rider-availability-card strong {
    font-weight: 700 !important;
    font-size: 16px !important;
}

/* Online / Offline button */
.rider-availability-card button[type="submit"] {
    padding: 13px 25px !important;
    border-radius: 12px !important;
    font-weight: 700 !important;
    font-size: 14px !important;
    border: none !important;
    cursor: pointer;
    transition: all 0.3s ease;
}

/* Button hover effect */
.rider-availability-card button[type="submit"]:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.15);
}

/* Mobile responsive design */
@media (max-width: 600px) {
    .main-content .rider-availability-card {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
