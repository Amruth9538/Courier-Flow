<?php
require_once __DIR__ . '/config.php';

// Only logged-in customers can access this page
require_auth();

if (($_SESSION['role'] ?? '') !== 'customer') {
    if (is_admin()) {
        redirect('dashboard.php');
    }

    redirect('rider-dashboard.php');
}

$customerName = $_SESSION['full_name'] ?? 'Customer';
// Get the logged-in customer's user ID
$customerId = (int) ($_SESSION['user_id'] ?? 0);
// Mark a customer notification as read
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'mark_notification_read') {

    // Verify the security token
    if (!isset($_POST['csrf_token']) || !hash_equals(
        (string) ($_SESSION['csrf_token'] ?? ''),
        (string) $_POST['csrf_token']
    ) || empty($_SESSION['csrf_token'])) {
        http_response_code(403);
        exit('Invalid security token');
    }

    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    if ($notificationId > 0) {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ? AND user_id = ?
        ");

        $stmt->execute([$notificationId, $customerId]);
    }

    header('Location: customer-dashboard.php');
    exit;
}

// Fetch notifications belonging only to this customer
$notificationStmt = $pdo->prepare("
    SELECT id, title, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT 20
");

$notificationStmt->execute([$customerId]);

$notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
// Count unread customer notifications
$unreadCount = 0;

foreach ($notifications as $notification) {
    if ((int) $notification['is_read'] === 0) {
        $unreadCount++;
    }
}
// Get parcels belonging to the logged-in customer
$parcelStmt = $pdo->prepare("
    SELECT
        p.id,
        p.tracking_number,
        p.recipient_name,
        p.recipient_address,
        p.status,
        p.created_at,
        u.full_name AS rider_name
    FROM parcels p
    LEFT JOIN riders r ON p.assigned_rider_id = r.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE p.customer_id = ?
    ORDER BY p.created_at DESC, p.id DESC
");

$parcelStmt->execute([$customerId]);

$customerParcels = $parcelStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard | CourierFlow</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body style="background:#f1f5f9;">

<main style="max-width:1000px; margin:40px auto; padding:20px;">

    <button class="btn btn-secondary page-back-button" type="button" data-back-button data-back-hide-without-history>
        Back
    </button>

    <div class="panel" style="padding:30px;">

        <h1>📦 CourierFlow Customer Dashboard</h1>

        <h2>
            Welcome,
            <?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>!
        </h2>

        <p>Track your deliveries and manage your parcel information.</p>

        <div style="
            background:#eff6ff;
            padding:25px;
            border-radius:12px;
            margin-top:25px;
        ">

            <h3>🚚 My Deliveries</h3>

            <?php if (empty($customerParcels)): ?>

    <p>You have no parcels yet.</p>

<?php else: ?>

    <div style="overflow-x:auto; margin-top:15px;">

        <table style="
            width:100%;
            border-collapse:collapse;
            background:white;
        ">

            <thead>
                <tr style="background:#dbeafe;">
                    <th style="padding:12px;">Tracking Number</th>
                    <th style="padding:12px;">Recipient</th>
                    <th style="padding:12px;">Delivery Address</th>
                    <th style="padding:12px;">Rider</th>
                    <th style="padding:12px;">Status</th>
                    <th style="padding:12px;">Created Date</th>
                    <th style="padding:12px;">Action</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($customerParcels as $parcel): ?>

                    <tr style="border-bottom:1px solid #e2e8f0;">

                        <td style="padding:12px;">
                            <?= htmlspecialchars($parcel['tracking_number'], ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td style="padding:12px;">
                            <?= htmlspecialchars($parcel['recipient_name'], ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td style="padding:12px;">
                            <?= htmlspecialchars($parcel['recipient_address'], ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td style="padding:12px;">
                            <?= htmlspecialchars($parcel['rider_name'] ?? 'Not Assigned', ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td style="padding:12px;">
                            <strong>
                                <?= htmlspecialchars($parcel['status'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                        </td>

                        <td style="padding:12px;">
                            <?= htmlspecialchars($parcel['created_at'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td style="padding:12px;">
    <a
        href="track.php?tracking=<?= urlencode($parcel['tracking_number']) ?>"
        class="btn btn-primary"
        style="display:inline-block; white-space:nowrap;"
    >
        📍 Track Parcel
    </a>
</td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

<?php endif; ?>

        </div>

        <div style="
            background:#f0fdf4;
            padding:25px;
            border-radius:12px;
            margin-top:20px;
        ">

            <h3>🔐 Delivery OTP Verification</h3>

            <p>
                Your secure delivery OTP will appear here
                when your parcel is ready for delivery.
            </p>

        </div>
        <!-- Customer Notifications Section -->
<div style="
    background:#ffffff;
    padding:25px;
    border-radius:12px;
    margin-top:20px;
    border:1px solid #e2e8f0;
">

    <h3>
    🔔 My Notifications

    <?php if ($unreadCount > 0): ?>
        <span style="
            background:#dc2626;
            color:white;
            padding:5px 10px;
            border-radius:20px;
            font-size:14px;
            margin-left:8px;
        ">
            <?= $unreadCount ?> Unread
        </span>
    <?php endif; ?>

</h3>

    <?php if (empty($notifications)): ?>

        <p>You have no notifications yet.</p>

    <?php else: ?>

        <?php foreach ($notifications as $notification): ?>

            <div style="
                background:<?= (int)$notification['is_read'] === 0 ? '#eff6ff' : '#f8fafc' ?>;
                padding:15px;
                margin-top:12px;
                border-radius:8px;
                border-left:4px solid #2563eb;
            ">

                <strong>
                    <?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?>
                </strong>

                <?php if ((int)$notification['is_read'] === 0): ?>
                    <span style="color:#2563eb; font-size:12px;">
                        (New)
                    </span>
                <?php endif; ?>

                <p>
                    <?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <small style="color:#64748b;">
                    <?= htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8') ?>
                </small>
                <?php if (!(int) $notification['is_read']): ?>

    <form method="POST" action="customer-dashboard.php" style="margin-top:10px;">

        <input type="hidden"
               name="csrf_token"
               value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <input type="hidden"
               name="action"
               value="mark_notification_read">

        <input type="hidden"
               name="notification_id"
               value="<?= (int) $notification['id'] ?>">

        <button type="submit"
                style="background:#2563eb; color:white; padding:8px 15px; border:none; border-radius:6px; cursor:pointer;">
            ✓ Mark as Read
        </button>

    </form>

<?php endif; ?>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

        <div style="margin-top:25px;">
            <a href="logout.php" class="btn btn-primary">
                Logout
            </a>
        </div>

    </div>

</main>

<script src="assets/js/back-navigation.js"></script>
</body>
</html>