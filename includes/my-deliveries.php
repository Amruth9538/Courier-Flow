
<?php
require_once __DIR__ . '/../config.php';
require_auth();

if (is_admin()) {
    redirect('dashboard.php');
}

$rider = ensure_rider_profile();

if (!$rider) {
    redirect('login.php');
}

$rider_id = (int) $rider['id'];

// Get only parcels assigned to this rider
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
    WHERE assigned_rider_id = ?
    ORDER BY created_at DESC
");

$stmt->execute([$rider_id]);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/header.php';?>

<style>
.deliveries-page {
    padding: 25px;
    color: #1e293b;
}

.deliveries-page h2 {
    color: #0f172a;
    margin-bottom: 8px;
}

.deliveries-page .page-description {
    color: #64748b;
    margin-bottom: 25px;
}

.delivery-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 18px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.04);
}

.delivery-box h3 {
    color: #1d4ed8;
    margin-top: 0;
}

.delivery-info {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.delivery-info p {
    margin: 5px 0;
    color: #334155;
    overflow-wrap: anywhere;
}

.delivery-info strong {
    color: #0f172a;
}

.delivery-status {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 13px;
    background: #dbeafe;
    color: #1d4ed8;
}

.delivery-status.delivered {
    background: #dcfce7;
    color: #166534;
}

.delivery-status.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.delivery-status.pending {
    background: #fef3c7;
    color: #92400e;
}

.delivery-action {
    display: inline-block;
    margin-top: 15px;
    padding: 10px 16px;
    background: #2563eb;
    color: #ffffff !important;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}

.delivery-action:hover {
    background: #1d4ed8;
}

.empty-deliveries {
    background: #ffffff;
    padding: 30px;
    border-radius: 12px;
    text-align: center;
    color: #64748b;
}

@media (max-width: 650px) {
    .delivery-info {
        grid-template-columns: 1fr;
    }

    .deliveries-page {
        padding: 15px;
    }
}
</style>

<div class="deliveries-page">

    <h2>My Deliveries</h2>
    <p class="page-description">
        View all parcels assigned to you.
    </p>

    <p>
        <strong>Total Assigned Deliveries:</strong>
        <?= count($deliveries) ?>
    </p>

    <?php if (empty($deliveries)): ?>

        <div class="empty-deliveries">
            <h3>No Deliveries Assigned</h3>
            <p>Your assigned parcels will appear here.</p>
        </div>

    <?php else: ?>

        <?php foreach ($deliveries as $delivery): ?>

            <?php
            $status = $delivery['status'] ?? 'Pending';
            $statusClass = strtolower(str_replace(' ', '-', $status));
            ?>

            <div class="delivery-box">

                <h3>
                    Tracking ID:
                    <?= htmlspecialchars($delivery['tracking_number'] ?? '') ?>
                </h3>

                <div class="delivery-info">

                    <p>
                        <strong>Recipient:</strong><br>
                        <?= htmlspecialchars($delivery['recipient_name'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Phone:</strong><br>
                        <?= htmlspecialchars($delivery['recipient_phone'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Delivery Address:</strong><br>
                        <?= htmlspecialchars($delivery['recipient_address'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Status:</strong><br>
                        <span class="delivery-status <?= htmlspecialchars($statusClass) ?>">
                            <?= htmlspecialchars($status) ?>
                        </span>
                    </p>

                    <p>
                        <strong>Created:</strong><br>
                        <?= htmlspecialchars($delivery['created_at'] ?? '') ?>
                    </p>

                </div>

                <a href="/parcel_delivery_system/delivery-details.php?id=<?= (int) $delivery['id'] ?>" class="delivery-action">
    View Delivery Details
</a>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
