<?php
require_once __DIR__ . '/../config.php';
require_auth();

if (!is_admin()) {
    redirect('rider-dashboard.php');
}
// Load registered customers for parcel assignment
$stmt_customers = $pdo->prepare("
    SELECT id, full_name, email
    FROM users
    WHERE role = 'customer'
      AND status = 'active'
    ORDER BY full_name ASC
");

$stmt_customers->execute();

$customers = $stmt_customers->fetchAll();
$message = $_SESSION['parcel_success'] ?? '';
unset($_SESSION['parcel_success']);

$error = '';

// 处理创建包裹逻辑
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'create_parcel') {

    verify_csrf();
// Get the customer selected by the admin
// Customer selection is optional
// Empty customer means walk-in customer

$customer_input = trim((string)($_POST['customer_id'] ?? ''));

$customer_id = null;

if ($customer_input !== '') {

    $customer_id = filter_var(
        $customer_input,
        FILTER_VALIDATE_INT
    );

    if ($customer_id === false || $customer_id <= 0) {

        $error = 'Please select a valid customer.';

    } else {

        // Check registered customer
        $customer_check = $pdo->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND role = 'customer'
              AND status = 'active'
            LIMIT 1
        ");

        $customer_check->execute([$customer_id]);

        if (!$customer_check->fetch()) {
            $error = 'Selected customer was not found or is inactive.';
        }
    }
}
    $recipient_name    = sanitize_string($_POST['recipient_name'] ?? '');
    $recipient_phone   = sanitize_string($_POST['recipient_phone'] ?? '');
    $parcel_type       = sanitize_string($_POST['parcel_type'] ?? '');
    $weight            = (float)($_POST['weight'] ?? 0);
    $assigned_rider_id = null;
    $status            = sanitize_string($_POST['status'] ?? 'Pending');
    $recipient_address = sanitize_string($_POST['recipient_address'] ?? '');
    // Read delivery GPS coordinates
$recipient_latitude = filter_var(
    $_POST['recipient_latitude'] ?? '',
    FILTER_VALIDATE_FLOAT,
    FILTER_NULL_ON_FAILURE
);

$recipient_longitude = filter_var(
    $_POST['recipient_longitude'] ?? '',
    FILTER_VALIDATE_FLOAT,
    FILTER_NULL_ON_FAILURE
);
    $pickup_address    = ''; // 移除 Pickup Address，默认为空

if ($error !== '') {
    // Customer validation failed; do not create the parcel.
} elseif (empty($recipient_name) || empty($recipient_phone) || empty($recipient_address)) {        $error = 'Please fill in all required fields.';
    } else {
    try {

        // Automatically select an online and available rider
       // STEP 1: Find the nearest online and available rider using GPS

$assigned_rider_id = null;

if ($recipient_latitude !== null && $recipient_longitude !== null) {

    $assign_stmt = $pdo->prepare("
        SELECT
            r.id,
            (
                6371 * ACOS(
                    LEAST(1, GREATEST(-1,
                        COS(RADIANS(?)) *
                        COS(RADIANS(r.current_latitude)) *
                        COS(
                            RADIANS(r.current_longitude) - RADIANS(?)
                        ) +
                        SIN(RADIANS(?)) *
                        SIN(RADIANS(r.current_latitude))
                    ))
                )
            ) AS distance_km

        FROM riders r
        JOIN users u ON r.user_id = u.id

        WHERE r.is_online = 1
          AND r.is_available = 1
          AND u.role = 'rider'
          AND r.current_latitude IS NOT NULL
          AND r.current_longitude IS NOT NULL

        ORDER BY distance_km ASC
        LIMIT 1
    ");

    $assign_stmt->execute([
        $recipient_latitude,
        $recipient_longitude,
        $recipient_latitude
    ]);

    $assigned_rider_id = $assign_stmt->fetchColumn();
}

// STEP 2: Fallback if GPS is unavailable or no GPS rider is found

if (!$assigned_rider_id) {

    $fallback_stmt = $pdo->prepare("
        SELECT r.id
        FROM riders r
        JOIN users u ON r.user_id = u.id
        WHERE r.is_online = 1
          AND r.is_available = 1
          AND u.role = 'rider'
        ORDER BY r.id DESC
        LIMIT 1
    ");

    $fallback_stmt->execute();

    $assigned_rider_id = $fallback_stmt->fetchColumn();
}

if (!$assigned_rider_id) {
    $assigned_rider_id = null;
}

        // Generate shipment tracking number
        $tracking_number = 'TRK' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

           $stmt = $pdo->prepare("
    INSERT INTO parcels (
        tracking_number,
        customer_id,
        recipient_name,
        recipient_phone,
        parcel_type,
        weight_kg,
        assigned_rider_id,
        status,
        pickup_address,
        recipient_address,
        recipient_latitude,
        recipient_longitude,
        created_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

$stmt->execute([
    $tracking_number,
    $customer_id,
    $recipient_name,
    $recipient_phone,
    $parcel_type,
    $weight,
    $assigned_rider_id,
    $status,
    $pickup_address,
    $recipient_address,
    $recipient_latitude,
    $recipient_longitude
]);
$new_parcel_id = (int) $pdo->lastInsertId();
// Mark the assigned rider as Busy if the shipment is active
if ($assigned_rider_id !== null &&
    !in_array($status, ['Delivered', 'Cancelled'], true)) {

    $busy_stmt = $pdo->prepare("
        UPDATE riders
        SET is_available = 0
        WHERE id = ?
    ");

    $busy_stmt->execute([$assigned_rider_id]);
}
// Create a notification for the registered customer
if ($customer_id !== null && $customer_id !== false) {

    $notification_title = 'Parcel Created';

    $notification_message =
        'Your parcel ' . $tracking_number .
        ' has been registered successfully. You can now track your delivery.';

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
        $new_parcel_id,
        $notification_title,
        $notification_message
    ]);
    // Notify customer when a rider is assigned
if ($assigned_rider_id !== null) {

    $rider_notification_title = 'Rider Assigned';

    $rider_notification_message =
        'Your parcel ' . $tracking_number .
        ' has been assigned to a delivery rider.';

    $rider_notification_stmt = $pdo->prepare("
        INSERT INTO notifications (
            user_id,
            parcel_id,
            title,
            message,
            is_read
        )
        VALUES (?, ?, ?, ?, 0)
    ");

    $rider_notification_stmt->execute([
        $customer_id,
        $new_parcel_id,
        $rider_notification_title,
        $rider_notification_message
    ]);
}
}
            $_SESSION['parcel_success'] = 'Parcel created successfully! Tracking #: ' . $tracking_number;
header('Location: parcels.php');
exit;
        } catch (Throwable $e) {
    $error = 'Failed to create parcel: ' . $e->getMessage();
}
    }
}

// 获取所有 Rider 列表用于下拉选择
$stmt_riders = $pdo->query("
    SELECT r.id, u.full_name
    FROM riders r
    JOIN users u ON r.user_id = u.id
    ORDER BY u.full_name ASC
");
$riders = $stmt_riders->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_parcel') {
    try {
        verify_csrf();

        $parcel_id = (int) ($_POST['parcel_id'] ?? 0);
        $recipient_name = sanitize_string($_POST['recipient_name'] ?? '');
        $recipient_phone = sanitize_string($_POST['recipient_phone'] ?? '');
        $parcel_type = sanitize_string($_POST['parcel_type'] ?? '');
        $weight = (float) ($_POST['weight'] ?? 0);
        $recipient_address = sanitize_string($_POST['recipient_address'] ?? '');
        // Get GPS coordinates from the parcel form
$recipient_address = sanitize_string($_POST['recipient_address'] ?? '');

$assigned_rider_id = !empty($_POST['assigned_rider_id']) ? (int) $_POST['assigned_rider_id'] : null;
        $assigned_rider_id = !empty($_POST['assigned_rider_id']) ? (int) $_POST['assigned_rider_id'] : null;
        $status = sanitize_string($_POST['status'] ?? 'Pending');
        $validStatuses = ['Pending', 'In Transit', 'Delivered', 'Cancelled'];

        if ($parcel_id <= 0 || $recipient_name === '' || $recipient_phone === '' || $recipient_address === '' || !in_array($status, $validStatuses, true)) {
            throw new RuntimeException('Please provide valid parcel details.');
        }

        if ($assigned_rider_id !== null) {
            $riderCheck = $pdo->prepare("SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND u.role = 'rider'");
            $riderCheck->execute([$assigned_rider_id]);
            if (!$riderCheck->fetch()) {
                throw new RuntimeException('Selected rider was not found.');
            }
        }

        $current = $pdo->prepare('SELECT recipient_address, assigned_rider_id FROM parcels WHERE id = ?');
        $current->execute([$parcel_id]);
        $existing = $current->fetch();
        if (!$existing) {
            throw new RuntimeException('Parcel not found.');
        }

        $addressChanged = $existing['recipient_address'] !== $recipient_address;
        $riderChanged = (int) ($existing['assigned_rider_id'] ?? 0) !== (int) ($assigned_rider_id ?? 0);
        $stmt = $pdo->prepare('UPDATE parcels SET recipient_name = ?, recipient_phone = ?, parcel_type = ?, weight_kg = ?, recipient_address = ?, assigned_rider_id = ?, status = ?, recipient_latitude = CASE WHEN ? THEN NULL ELSE recipient_latitude END, recipient_longitude = CASE WHEN ? THEN NULL ELSE recipient_longitude END, recipient_geocoded_at = CASE WHEN ? THEN NULL ELSE recipient_geocoded_at END WHERE id = ?');
        $stmt->execute([$recipient_name, $recipient_phone, $parcel_type, $weight, $recipient_address, $assigned_rider_id, $status, $addressChanged, $addressChanged, $addressChanged, $parcel_id]);
        if ($addressChanged || $riderChanged) {
            // A different rider or destination needs a fresh A-to-C plan and
            // must not show the previous rider's actual GPS history.
            $pdo->prepare('DELETE FROM parcel_route_history WHERE parcel_id = ?')->execute([$parcel_id]);
            $pdo->prepare('DELETE FROM parcel_route_plans WHERE parcel_id = ?')->execute([$parcel_id]);
            $pdo->prepare('DELETE FROM delivery_route_snapshots WHERE parcel_id = ?')->execute([$parcel_id]);
        }
        // AUTO ASSIGN WAITING PARCEL AFTER DELIVERY

if (in_array($status, ['Delivered', 'Cancelled'], true)
    && $assigned_rider_id !== null) {

    // Check whether this rider has another active parcel
    $active_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM parcels
        WHERE assigned_rider_id = ?
          AND status NOT IN ('Delivered', 'Cancelled')
    ");

    $active_stmt->execute([$assigned_rider_id]);

    $active_count = (int) $active_stmt->fetchColumn();

    if ($active_count === 0) {

        // Find the oldest unassigned pending parcel
        $waiting_stmt = $pdo->prepare("
            SELECT id
            FROM parcels
            WHERE assigned_rider_id IS NULL
              AND status = 'Pending'
            ORDER BY created_at ASC, id ASC
            LIMIT 1
        ");

        $waiting_stmt->execute();

        $waiting_parcel_id = $waiting_stmt->fetchColumn();

        if ($waiting_parcel_id) {

            // Assign waiting parcel to the rider
            $assign_waiting = $pdo->prepare("
                UPDATE parcels
                SET assigned_rider_id = ?
                WHERE id = ?
                  AND assigned_rider_id IS NULL
                  AND status = 'Pending'
            ");

            $assign_waiting->execute([
                $assigned_rider_id,
                $waiting_parcel_id
            ]);

            // Rider stays Busy
            $pdo->prepare("
                UPDATE riders
                SET is_available = 0
                WHERE id = ?
            ")->execute([$assigned_rider_id]);

        } else {

            // No waiting parcel: rider becomes Available
            $pdo->prepare("
                UPDATE riders
                SET is_available = 1
                WHERE id = ?
                  AND is_online = 1
            ")->execute([$assigned_rider_id]);
        }
    }
}
        $message = 'Parcel updated successfully.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_parcel') {
    try {
        verify_csrf();

        $parcelId = (int) ($_POST['parcel_id'] ?? 0);
        if ($parcelId <= 0) {
            throw new RuntimeException('Invalid parcel ID.');
        }

        $parcelCheck = $pdo->prepare('SELECT tracking_number, recipient_name FROM parcels WHERE id = ?');
        $parcelCheck->execute([$parcelId]);
        $parcelData = $parcelCheck->fetch();
        if (!$parcelData) {
            throw new RuntimeException('Parcel not found.');
        }

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM parcels WHERE id = ?')->execute([$parcelId]);
        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
            $_SESSION['user_id'],
            'Parcel Deleted',
            'Deleted parcel ' . $parcelData['tracking_number'] . ' (' . $parcelData['recipient_name'] . ')',
        ]);
        $pdo->commit();
        $message = 'Parcel deleted successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// 获取所有包裹列表
$stmt_parcels = $pdo->query("
    SELECT p.*, u.full_name as rider_name
    FROM parcels p
    LEFT JOIN riders r ON p.assigned_rider_id = r.id
    LEFT JOIN users u ON r.user_id = u.id
    ORDER BY p.id DESC
");
$parcels = $stmt_parcels->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    /* CourierFlow Admin Console Background */

/* Clean CourierFlow Admin Background */
/* CourierFlow Professional Logistics Background */
/* COURIERFLOW PREMIUM PARCEL MANAGEMENT */

/* Delivery truck and GPS background */
body {
    background-image: url('../assets/images/courierflow-bg.png') !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Main dashboard container */
.main-content {
    background: rgba(240, 248, 255, 0.88) !important;
    border: 1px solid rgba(255, 255, 255, 0.85) !important;
    border-radius: 20px !important;
    padding: 28px !important;
    margin: 0 18px 24px !important;
    width: auto !important;
    min-height: calc(100vh - 48px);
    box-shadow: 0 15px 45px rgba(15, 23, 42, 0.15);
}

/* Beautiful white cards */
.main-content .panel.card {
    background: rgba(255, 255, 255, 0.97) !important;
    border-radius: 16px !important;
    border: 1px solid #e0edff !important;
    padding: 24px !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.08);
}

/* Page heading */
.main-content .page-title {
    color: #0b2c5d !important;
    font-size: 28px !important;
    font-weight: 800 !important;
}

/* Form fields */
.main-content .form-input,
.main-content .form-select {
    border: 1px solid #cfe0f5 !important;
    border-radius: 9px !important;
    background: white !important;
    padding: 10px 12px !important;
}

/* Input focus */
.main-content input:focus,
.main-content select:focus {
    outline: none !important;
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

/* Create Parcel button */
.main-content .btn-primary {
    background: #2563eb !important;
    border-radius: 9px !important;
    border: none !important;
}

/* Parcel list */
.main-content .data-table {
    width: 100%;
    border-collapse: collapse;
}

.main-content .data-table th {
    color: #0b2c5d;
    font-weight: 700;
}

.main-content .data-table td {
    border-bottom: 1px solid #e2e8f0;
}

/* Responsive layout */
@media (max-width: 768px) {
    .main-content {
        margin: 12px !important;
        padding: 16px !important;
    }
}

body {
    background-image:
        linear-gradient(
            rgba(235, 245, 255, 0.25),
            rgba(235, 245, 255, 0.35)
        ),
        url('../assets/images/courierflow-bg.png') !important;

    background-size: cover !important;
    background-position: center center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Show the background behind the parcel management page */
.main-content {
    background: transparent !important;
}

/* Professional white cards */
.main-content .panel.card {
    background: rgba(255, 255, 255, 0.96) !important;
    border: 1px solid rgba(219, 234, 254, 0.9);
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
    padding: 20px;
}

/* Modern page heading */
.page-title {
    color: #0f2d5c !important;
    font-weight: 800;
}

body {
    background: linear-gradient(
        135deg,
        #e0f2fe 0%,
        #f0f9ff 45%,
        #dbeafe 100%
    ) !important;
    background-attachment: fixed !important;
}

.main-content {
    background: transparent !important;
}

/* Make the admin content slightly transparent */
.main-content {
    background: transparent !important;
}

/* Modern white panels */
.main-content .panel.card {
    background: rgba(255, 255, 255, 0.96);
    border: 1px solid rgba(255, 255, 255, 0.8);
    border-radius: 14px;
    box-shadow: 0 8px 30px rgba(15, 23, 42, 0.10);
    padding: 20px;
}

/* Improve headings */
.page-title {
    color: #0f2d5c;
    font-weight: 800;
}
.address-autocomplete-wrapper {
    position: relative;
    width: 100%;
}

.address-autocomplete-wrapper .form-input {
    width: 100%;
    min-height: 52px;
    padding: 10px 12px;
    border: 1px solid #dfe7f1;
    border-radius: 10px;
    font: inherit;
    font-size: 1rem;
}

.address-autocomplete-wrapper .form-input:focus {
    outline: none;
    border-color: #2f6fed;
}

.address-input-group {
    display: flex;
    gap: 8px;
    align-items: center;
}

.btn-locate {
    background-color: #10b981;
    color: #ffffff;
    border: none;
    padding: 10px 16px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: background-color 0.15s ease;
}

.btn-locate:hover {
    background-color: #059669;
}

/* 搜索下拉结果框样式 */
.address-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #ffffff;
    border: 2px solid #2563eb;
    border-radius: 6px;
    max-height: 280px;
    overflow-y: auto;
    z-index: 1000;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    display: none;
    margin-top: 4px;
}

.address-dropdown-item {
    padding: 12px 14px;
    cursor: pointer;
    border-bottom: 1px solid #e2e8f0;
    transition: background-color 0.15s ease;
}

.address-dropdown-item:last-child {
    border-bottom: none;
}

.address-dropdown-item:hover {
    background-color: #f1f5f9;
}

.address-item-title {
    font-weight: 700;
    font-size: 14px;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 6px;
}

.address-item-title .pin-icon {
    color: #e11d48;
    font-size: 14px;
}

.address-item-subtitle {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.4;
}
/* Show the CourierFlow background behind the dashboard */

body {
    background-image: url('../assets/images/courierflow-bg.png') !important;
    background-size: cover !important;
    background-position: center center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Remove the solid background covering the image */
.app-shell,
.content {
    background: transparent !important;
}

/* Keep the main dashboard panel slightly transparent */
.main-content {
    background: rgba(235, 245, 255, 0.88) !important;
    border-radius: 20px !important;
}
/* ==========================================
   COURIERFLOW PREMIUM PARCEL LIST
========================================== */

/* Table styling */
.main-content .data-table {
    width: 100%;
    border-collapse: collapse;
    background: transparent;
}

/* Table headings */
.main-content .data-table thead th {
    background: #f1f7ff !important;
    color: #0b3670 !important;
    font-size: 13px;
    font-weight: 800;
    padding: 15px 12px !important;
    text-align: left;
    border-bottom: 2px solid #dbeafe;
}

/* Table rows */
.main-content .data-table tbody td {
    padding: 15px 12px !important;
    border-bottom: 1px solid #e2e8f0;
    color: #173b70;
    font-size: 13px;
    vertical-align: middle;
}

/* Alternate row background */
.main-content .data-table tbody tr:nth-child(even) {
    background: #f8fbff;
}

/* Hover effect */
.main-content .data-table tbody tr:hover {
    background: #eaf3ff !important;
}

/* Tracking number */
.main-content .data-table tbody td:first-child {
    color: #082b60 !important;
    font-weight: 800;
}

/* Edit button */
.main-content .data-table .btn-secondary {
    background: #e8f1ff !important;
    color: #0b3670 !important;
    border-radius: 8px !important;
    border: none !important;
}

/* Delete button */
.main-content .data-table .btn-danger {
    background: #ef4444 !important;
    color: white !important;
    border-radius: 8px !important;
    border: none !important;
}

/* Table button hover */
.main-content .data-table .btn:hover {
    transform: translateY(-1px);
    transition: 0.2s;
}
</style>

<div class="main-content">
    <div class="header-bar">
        <div>
            <h1 class="page-title">Parcels Management</h1>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- 创建包裹表单 -->
    <div class="panel card" style="margin-bottom: 24px;">
        <h2 class="card-title" style="font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 20px;">
    <i class="bi bi-box-seam-fill" style="color: #2563eb; margin-right: 10px;"></i>
    Create New Parcel
</h2>

        <form action="parcels.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_parcel">
            <!-- Select Customer -->
<div class="form-group" style="margin-bottom: 16px;">

    <label class="form-label">Select Customer</label>

    <select name="customer_id" class="form-select">

    <option value="">-- Walk-in Customer / No Account --</option>

    <?php foreach ($customers as $customer): ?>
        <option value="<?= (int) $customer['id'] ?>">
            <?= htmlspecialchars($customer['full_name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
    <?php endforeach; ?>

</select>
</div>
            <input type="hidden" name="recipient_latitude" id="recipient_latitude">
            <input type="hidden" name="recipient_longitude" id="recipient_longitude">

            <!-- 第一排基本信息 -->
            <div class="form-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Recipient Name</label>
                    <input type="text" name="recipient_name" class="form-input" placeholder="e.g. John Doe" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Recipient Phone</label>
                    <input
    type="tel"
    name="recipient_phone"
    id="recipient_phone"
    maxlength="10"
    minlength="10"
    placeholder="e.g. 0123456789"
    pattern="[0-9]{10}"
    inputmode="numeric"
    title="Please enter exactly 10 digits"
    oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)"
    required
>
                </div>

                <div class="form-group">
                    <label class="form-label">Parcel Type</label>
                    <input type="text" name="parcel_type" class="form-input" placeholder="e.g. Document, Electronics">
                </div>

                <div class="form-group">
                    <label class="form-label">Weight (kg)</label>
                    <input type="number" step="0.01" name="weight" class="form-input" placeholder="e.g. 1.50">
                </div>
            </div>

            <!-- 第二排派送选项 -->
            <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label">Assign Rider</label>
                    <select name="assigned_rider_id" class="form-select">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($riders as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="Pending">Pending</option>
                        <option value="In Transit">In Transit</option>
                        <option value="Delivered">Delivered</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

                        <!-- Delivery Address -->
<div class="form-group" style="margin-bottom: 20px;">
    <label class="form-label">Delivery Address (Search or Locate)</label>

    <div class="address-input-group">
        <div class="address-autocomplete-wrapper">
            <input
                type="text"
                id="recipient_address"
                name="recipient_address"
                class="form-input"
                placeholder="Search village, street, landmark or PIN code..."
                autocomplete="off"
                required
            >

            <div id="recipient_dropdown" class="address-dropdown"></div>
        </div>

        <button
            type="button"
            class="btn-locate"
            onclick="locateAddress('recipient_address')">
            📍 Use Current Location
        </button>

        <!-- NEW MAP BUTTON -->
        <button
            type="button"
            class="btn-locate"
            id="openDeliveryMap">
            🗺️ Select Exact Location on Map
            </div> <!-- Close address-input-group -->
            </div> <!-- Close Delivery Address form-group -->
        </button>
            <!-- Exact Delivery Location Map -->
             <div id="deliveryMapSection" style="display:none; width:100%; margin-top:20px; margin-bottom:20px;">

    <h3>📍 Select Exact Delivery Location</h3>

    <p>Click on the map to select the exact delivery location.</p>

    <div id="deliveryMap"
         style="height:350px; width:100%; border-radius:12px;">
    </div>
    <button type="button"
            id="confirmDeliveryLocation"
            class="btn btn-primary"
            style="margin-top:10px;">
        ✅ Confirm Location
    </button>
</div> <!-- Close deliveryMapSection -->

<div style="display: flex; justify-content: flex-end; margin-top: 15px;">
    <button type="submit"
            class="btn btn-primary"
            style="padding: 10px 24px;">
        + Create Parcel
    </button>
</div>

</form>

</div> <!-- Close Create New Parcel card -->

    <!-- 包裹列表 -->
    <div class="panel card">
        <h2 class="card-title" style="font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 20px;">
    <i class="bi bi-list-check" style="color: #2563eb; margin-right: 10px;"></i>
    Parcel List
</h2>
        <table class="data-table" style="width: 100%; border-collapse: separate; border-spacing: 0;">
            <thead>
                <tr>
                    <th>Tracking #</th>
                    <th>Recipient</th>
                    <th>Type / Weight</th>
                    <th>Delivery Address</th>
                    <th>Assigned Rider</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($parcels)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 24px; color: #94a3b8;">
                            No parcels found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($parcels as $p): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['tracking_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($p['recipient_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                <small style="color: #64748b;"><?= htmlspecialchars($p['recipient_phone'], ENT_QUOTES, 'UTF-8') ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($p['parcel_type'] ?: 'Standard', ENT_QUOTES, 'UTF-8') ?><br>
                                <small style="color: #64748b;"><?= $p['weight_kg'] > 0 ? $p['weight_kg'] . ' kg' : '-' ?></small>
                            </td>
                            <td style="font-size: 13px; max-width: 280px; color: #0f172a;">
                                <?= htmlspecialchars($p['recipient_address'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($p['rider_name'] ?: 'Unassigned', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?php $status_slug = strtolower(str_replace(' ', '_', $p['status'])); ?>
                                <span class="badge badge-<?= $status_slug ?>">
                                    <?= htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td style="display: flex; gap: 6px;">
                                <button type="button" class="btn btn-secondary btn-edit-parcel"
                                        data-id="<?= (int) $p['id'] ?>"
                                        data-name="<?= htmlspecialchars($p['recipient_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-phone="<?= htmlspecialchars($p['recipient_phone'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-type="<?= htmlspecialchars($p['parcel_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-weight="<?= htmlspecialchars((string) ($p['weight_kg'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-address="<?= htmlspecialchars($p['recipient_address'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-rider-id="<?= (int) ($p['assigned_rider_id'] ?? 0) ?>"
                                        data-status="<?= htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8') ?>">Edit</button>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete parcel <?= htmlspecialchars($p['tracking_number'], ENT_QUOTES, 'UTF-8') ?>? This action cannot be undone.');" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_parcel">
                                    <input type="hidden" name="parcel_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="editParcelModal" style="display: none; position: fixed; z-index: 9999; inset: 0; padding: 24px; overflow-y: auto; background: rgba(15, 23, 42, 0.6);">
    <div style="max-width: 760px; margin: 40px auto; padding: 28px; border-radius: 12px; background: #fff; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <h2 style="margin: 0 0 20px;">Edit Parcel</h2>
        <form method="POST" action="parcels.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_parcel">
            <input type="hidden" id="edit_parcel_id" name="parcel_id">
            <div class="form-grid" style="margin-bottom: 16px;">
                <label>Recipient Name<input id="edit_recipient_name" type="text" name="recipient_name" required></label>
                <label>Recipient Phone<input id="edit_recipient_phone" type="text" name="recipient_phone" required></label>
                <label>Parcel Type<input id="edit_parcel_type" type="text" name="parcel_type"></label>
                <label>Weight (kg)<input id="edit_weight" type="number" step="0.01" min="0" name="weight"></label>
                <label>Assigned Rider
                    <select id="edit_assigned_rider_id" name="assigned_rider_id">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($riders as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Status
                    <select id="edit_status" name="status">
                        <option value="Pending">Pending</option>
                        <option value="In Transit">In Transit</option>
                        <option value="Delivered">Delivered</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </label>
            </div>
            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label">Delivery Address (Search Malaysia Address)</label>
                <div class="address-autocomplete-wrapper">
                    <input id="edit_recipient_address" type="text" name="recipient_address" class="form-input" placeholder="Search address or location..." autocomplete="off" required>
                    <div id="edit_recipient_dropdown" class="address-dropdown"></div>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" id="closeEditParcelModal" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>


<style>
/* CourierFlow section heading icons */

.main-content .panel.card h2 i,
.main-content .panel.card h3 i {
    color: #2563eb;
    margin-right: 10px;
    font-size: 22px;
    vertical-align: middle;
}
</style>
<!-- Leaflet Map Library -->
<link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
/>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    // CourierFlow Exact Delivery Location Map
let deliveryMap = null;
let deliveryMarker = null;
let selectedDeliveryLat = null;
let selectedDeliveryLon = null;

document.addEventListener('DOMContentLoaded', function () {

    const mapButton = document.getElementById('openDeliveryMap');
    const mapSection = document.getElementById('deliveryMapSection');
    const confirmButton = document.getElementById('confirmDeliveryLocation');

    if (!mapButton || !mapSection || !confirmButton) return;

    mapButton.addEventListener('click', function () {

        mapSection.style.display = 'block';

        if (!deliveryMap) {

            // Initially show Karnataka
            deliveryMap = L.map('deliveryMap', {
    zoomControl: false
}).setView([14.4644, 75.9218], 13);

L.control.zoom({
    position: 'bottomright'
}).addTo(deliveryMap);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(deliveryMap);

            deliveryMap.on('click', function (event) {

                selectedDeliveryLat = event.latlng.lat;
                selectedDeliveryLon = event.latlng.lng;

                if (deliveryMarker) {
                    deliveryMarker.setLatLng(event.latlng);
                } else {
                 deliveryMarker = L.marker(event.latlng, {
    icon: L.divIcon({
        className: 'courierflow-delivery-marker',
        html: '<div style="background:#10b981;color:white;width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;border:3px solid white;box-shadow:0 4px 15px rgba(0,0,0,0.3);">📍</div>',
        iconSize: [42, 42],
        iconAnchor: [21, 42]
    })
}).addTo(deliveryMap);
                }
            });
        }

        setTimeout(function () {
            deliveryMap.invalidateSize();
        }, 150);
    });

    confirmButton.addEventListener('click', function () {

        if (selectedDeliveryLat === null || selectedDeliveryLon === null) {
            alert('Please click a location on the map first.');
            return;
        }

        const addressInput = document.getElementById('recipient_address');

        // Save selected GPS coordinates in the input
        addressInput.dataset.latitude = selectedDeliveryLat;
        addressInput.dataset.longitude = selectedDeliveryLon;
        // Store GPS coordinates for the address field
addressInput.dataset.latitude = selectedDeliveryLat;
addressInput.dataset.longitude = selectedDeliveryLon;

// Send GPS coordinates to PHP when creating the parcel
document.getElementById('recipient_latitude').value = selectedDeliveryLat;
document.getElementById('recipient_longitude').value = selectedDeliveryLon;

        // Retrieve the address for the selected map location
fetch(`https://nominatim.openstreetmap.org/reverse?format=json&addressdetails=1&accept-language=en&lat=${selectedDeliveryLat}&lon=${selectedDeliveryLon}`)            .then(response => response.json())
            .then(data => {

                if (data && data.display_name) {
                    addressInput.value = data.display_name;
                } else {
                    addressInput.value = `${selectedDeliveryLat}, ${selectedDeliveryLon}`;
                }

                mapSection.style.display = 'none';

                alert('Delivery location selected successfully!');
            })
            .catch(function () {
                addressInput.value = `${selectedDeliveryLat}, ${selectedDeliveryLon}`;
                mapSection.style.display = 'none';
                alert('Location selected. Please enter the complete delivery address manually.');
            });
    });
});
function locateAddress(inputId) {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
    }
    const input = document.getElementById(inputId);
    const originalPlaceholder = input.placeholder;
    input.placeholder = 'Locating current position...';
    input.value = '';

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const lat = position.coords.latitude;
            const lon = position.coords.longitude;
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}`)
                .then(res => res.json())
                .then(data => {
                    if (data && data.display_name) {
                        input.value = data.display_name;
                    } else {
                        alert('Could not find address for your location.');
                    }
                    input.placeholder = originalPlaceholder;
                })
                .catch(() => {
                    alert('Error getting address.');
                    input.placeholder = originalPlaceholder;
                });
        },
        () => {
            alert('Unable to retrieve your location. Please grant permission.');
            input.placeholder = originalPlaceholder;
        }
    );
}

document.addEventListener('DOMContentLoaded', function () {
    const editModal = document.getElementById('editParcelModal');
    const closeEditModal = document.getElementById('closeEditParcelModal');

    document.querySelectorAll('.btn-edit-parcel').forEach(button => {
        button.addEventListener('click', function () {
            document.getElementById('edit_parcel_id').value = this.dataset.id;
            document.getElementById('edit_recipient_name').value = this.dataset.name;
            document.getElementById('edit_recipient_phone').value = this.dataset.phone;
            document.getElementById('edit_parcel_type').value = this.dataset.type;
            document.getElementById('edit_weight').value = this.dataset.weight;
            document.getElementById('edit_recipient_address').value = this.dataset.address;
            document.getElementById('edit_assigned_rider_id').value = this.dataset.riderId;
            document.getElementById('edit_status').value = this.dataset.status;
            editModal.style.display = 'block';
        });
    });

    closeEditModal.addEventListener('click', () => { editModal.style.display = 'none'; });
    editModal.addEventListener('click', event => {
        if (event.target === editModal) editModal.style.display = 'none';
    });

    function setupAddressAutocomplete(inputId, dropdownId) {
        const input = document.getElementById(inputId);
        const dropdown = document.getElementById(dropdownId);
        let timeout = null;

        if (!input || !dropdown) return;

        input.addEventListener('input', function () {
            clearTimeout(timeout);
            const query = this.value.trim();

            if (query.length < 2) {
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
                return;
            }

            timeout = setTimeout(() => {
                const url = `https://nominatim.openstreetmap.org/search?format=json&countrycodes=in&limit=50&q=${encodeURIComponent(query)}`;

                fetch(url, {
                    headers: {
                        'Accept-Language': 'en,ms'
                    }
                })
                .then(res => res.json())
                .then(data => {
                    dropdown.innerHTML = '';
                    if (data && data.length > 0) {
                        dropdown.style.display = 'block';
                        data.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'address-dropdown-item';
                            const fullAddr = item.display_name;
                            const firstCommaIndex = fullAddr.indexOf(',');
                            let title = fullAddr;
                            let subtitle = '';

                            if (firstCommaIndex !== -1) {
                                title = fullAddr.substring(0, firstCommaIndex).trim();
                                subtitle = fullAddr.substring(firstCommaIndex + 1).trim();
                            }

                            div.innerHTML = `
                                <div class="address-item-title"><span class="pin-icon">📍</span> ${title}</div>
                                ${subtitle ? `<div class="address-item-subtitle">${subtitle}</div>` : ''}
                            `;

                           div.addEventListener('click', function () {

    // Display the selected address
    input.value = fullAddr;

    // Store GPS coordinates
    input.dataset.latitude = item.lat;
    input.dataset.longitude = item.lon;

    // Send GPS coordinates to the parcel form
    if (inputId === 'recipient_address') {
        document.getElementById('recipient_latitude').value = item.lat;
        document.getElementById('recipient_longitude').value = item.lon;
    }

    // Close the address suggestions
    dropdown.style.display = 'none';

});

                            dropdown.appendChild(div);
                        });
                    } else {
                        dropdown.style.display = 'none';
                    }
                })
                .catch(() => {
                    dropdown.style.display = 'none';
                });
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

   setupAddressAutocomplete('recipient_address', 'recipient_dropdown');
setupAddressAutocomplete('edit_recipient_address', 'edit_recipient_dropdown');
});

</script>

<style>
.content {
    background: rgba(235, 245, 255, 0.82) !important;
    border-radius: 22px !important;
    margin: 20px !important;
    padding: 20px !important;
    box-shadow: 0 12px 35px rgba(15, 23, 42, 0.12);
}

/* Header becomes part of the same visual panel */
.content > .topbar {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    margin-bottom: 0 !important;
    padding: 8px 12px 14px !important;
}

/* Remove the second panel's outer background */
.content > .main-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    margin: 0 !important;
    padding: 0 12px 12px !important;
    min-height: auto !important;
}

/* Make the page heading prominent */
.main-content .page-title {
    font-size: 30px !important;
    color: #082b60 !important;
    margin-bottom: 18px !important;
}

/* Keep the form and table as separate white cards */
.main-content .panel.card {
    background: rgba(255, 255, 255, 0.96) !important;
    border-radius: 16px !important;
    padding: 22px !important;
}
/* ==========================================
   COURIERFLOW PREMIUM PARCEL FORM
========================================== */

/* Form card */
.main-content .panel.card {
    background: rgba(255, 255, 255, 0.97) !important;
    border-radius: 16px !important;
    padding: 24px !important;
    border: 1px solid rgba(219, 234, 254, 0.9) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.06) !important;
}

/* Form heading */
.main-content .panel.card h2,
.main-content .panel.card h3 {
    color: #082b60 !important;
    font-weight: 800 !important;
}

/* Form labels */
.main-content .panel.card label {
    color: #123d77 !important;
    font-weight: 600 !important;
    font-size: 13px !important;
}

/* Input fields */
.main-content .panel.card input,
.main-content .panel.card select,
.main-content .panel.card textarea {
    border: 1px solid #c8dcf7 !important;
    border-radius: 9px !important;
    background: #ffffff !important;
    transition: border-color 0.2s, box-shadow 0.2s;
}

/* Input focus */
.main-content .panel.card input:focus,
.main-content .panel.card select:focus,
.main-content .panel.card textarea:focus {
    border-color: #2563eb !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12) !important;
}

/* Primary button */
.main-content .btn-primary {
    background: linear-gradient(90deg, #2563eb, #3b82f6) !important;
    border: none !important;
    border-radius: 9px !important;
    font-weight: 700 !important;
    padding: 11px 20px !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    transition: transform 0.2s, box-shadow 0.2s;
}

.main-content .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 18px rgba(37, 99, 235, 0.3);
}
/* ==========================================
   COURIERFLOW UNIFIED DASHBOARD DESIGN
========================================== */

/* One large dashboard panel */
.app-shell > .content {
    background: rgba(238, 247, 255, 0.88) !important;
    border: 1px solid rgba(255, 255, 255, 0.9) !important;
    border-radius: 20px !important;
    padding: 20px !important;
    margin: 15px !important;
    box-shadow: 0 12px 35px rgba(15, 23, 42, 0.12);
}

/* Remove separate white header card */
.app-shell > .content > .topbar {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 10px 12px 20px !important;
    margin: 0 !important;
}

/* Remove separate background around Parcels Management */
.app-shell > .content > .main-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    margin: 0 !important;
    padding: 0 12px 12px !important;
    min-height: auto !important;
}

/* Maintain spacing between form and table */
.main-content .panel.card {
    margin-bottom: 20px !important;
}
/* FINAL COURIERFLOW DASHBOARD LAYOUT */

/* One rounded dashboard panel */
.app-shell > .content {
    background: rgba(238, 247, 255, 0.92) !important;
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
    padding: 5px 10px 20px !important;
}

/* Parcels Management section */
.app-shell > .content > .main-content {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    margin: 0 !important;
    padding: 0 10px 10px !important;
    min-height: auto !important;
}

/* White form and table cards */
.main-content .panel.card {
    background: rgba(255, 255, 255, 0.97) !important;
    border-radius: 16px !important;
    margin-bottom: 20px !important;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>