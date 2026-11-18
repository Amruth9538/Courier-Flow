
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

$rider_id = (int) $rider['id'];
$parcel_id = (int) ($_GET['id'] ?? 0);
$delivery = null;

if ($parcel_id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            tracking_number,
            recipient_name,
            recipient_phone,
            recipient_address,
            status,
            created_at
        FROM parcels
        WHERE id = ? AND assigned_rider_id = ?
        LIMIT 1
    ");

    $stmt->execute([$parcel_id, $rider_id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.delivery-details-page {
    padding: 25px;
    color: #1e293b;
}

.delivery-details-card {
    max-width: 850px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 25px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
}

.delivery-details-card h2 {
    color: #0f172a;
    margin-top: 0;
}

.detail-item {
    padding: 15px 0;
    border-bottom: 1px solid #e2e8f0;
}

.detail-item strong {
    display: block;
    color: #64748b;
    margin-bottom: 6px;
}

.detail-item span {
    color: #1e293b;
    overflow-wrap: anywhere;
}

.details-button {
    display: inline-block;
    margin-top: 20px;
    padding: 11px 18px;
    background: #2563eb;
    color: white !important;
    border-radius: 8px;
    text-decoration: none;
}

.details-message {
    background: #ffffff;
    padding: 22px;
    border-radius: 12px;
    color: #334155;
}

@media (max-width: 650px) {
    .delivery-details-page {
        padding: 15px;
    }
}
</style>

<div class="delivery-details-page">
    <h2>Delivery Details</h2>

    <?php if (!$delivery): ?>
        <div class="details-message">
            <h3>Delivery Not Found</h3>
            <p>This parcel is not assigned to your account, or the delivery ID is invalid.</p>
        </div>
    <?php else: ?>
        <div class="delivery-details-card">
            <h2>
                Tracking ID:
                <?= htmlspecialchars($delivery['tracking_number'] ?? '') ?>
            </h2>

            <div class="detail-item">
                <strong>Recipient Name</strong>
                <span><?= htmlspecialchars($delivery['recipient_name'] ?? '') ?></span>
            </div>

            <div class="detail-item">
                <strong>Phone Number</strong>
                <span><?= htmlspecialchars($delivery['recipient_phone'] ?? '') ?></span>
            </div>

            <div class="detail-item">
                <strong>Delivery Address</strong>
                <span><?= htmlspecialchars($delivery['recipient_address'] ?? '') ?></span>
            </div>

            <div class="detail-item">
                <strong>Current Status</strong>
                <span><?= htmlspecialchars($delivery['status'] ?? '') ?></span>
            </div>

            <div class="detail-item">
                <strong>Created Date</strong>
                <span><?= htmlspecialchars($delivery['created_at'] ?? '') ?></span>
            </div>

            <a href="my-deliveries.php" class="details-button">
                Back to My Deliveries
            </a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
