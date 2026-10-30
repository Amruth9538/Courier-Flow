<?php
require_once __DIR__ . '/config.php';
require_auth();

$parcelId = (int) ($_GET['id'] ?? 0);
$parcel = $pdo->prepare('SELECT p.*, u.full_name AS rider_name FROM parcels p LEFT JOIN riders r ON r.id = p.assigned_rider_id LEFT JOIN users u ON u.id = r.user_id WHERE p.id = ?');
$parcel->execute([$parcelId]);
$parcel = $parcel->fetch();

if (!$parcel) {
    redirect('rider-dashboard.php');
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $status = $_POST['status'] ?? 'Pending';
    $remarks = sanitize_string($_POST['remarks'] ?? '');
    $photoPath = '';

    if (!empty($_FILES['proof_photo']['name'])) {
        $targetDir = __DIR__ . '/uploads/proofs/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileName = time() . '_' . basename($_FILES['proof_photo']['name']);
        $targetPath = $targetDir . $fileName;
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($_FILES['proof_photo']['type'], $allowed, true)) {
            $message = 'Only JPEG, PNG, and WebP images are allowed.';
        } elseif ($_FILES['proof_photo']['size'] > 2 * 1024 * 1024) {
            $message = 'Image must be 2MB or smaller.';
        } elseif (move_uploaded_file($_FILES['proof_photo']['tmp_name'], $targetPath)) {
            $photoPath = 'uploads/proofs/' . $fileName;
            $riderProfile = ensure_rider_profile();
            if ($riderProfile) {
                $pdo->prepare('INSERT INTO delivery_photos (parcel_id, rider_id, file_path) VALUES (?, ?, ?)')->execute([$parcelId, $riderProfile['id'], $photoPath]);
            }
        }
    }

    if ($message === '') {
        $pdo->prepare('UPDATE parcels SET status = ? WHERE id = ?')->execute([$status, $parcelId]);
        $pdo->prepare('INSERT INTO parcel_status_history (parcel_id, status, remarks, photo_path, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$parcelId, $status, $remarks, $photoPath, $_SESSION['user_id']]);
        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
            $_SESSION['user_id'],
            'Parcel Update',
            'Parcel ' . $parcel['tracking_number'] . ' updated to ' . $status
        ]);
        $message = 'Parcel updated.';
        $parcel['status'] = $status;
    }
}

$history = $pdo->prepare('SELECT h.*, u.full_name FROM parcel_status_history h LEFT JOIN users u ON u.id = h.created_by WHERE h.parcel_id = ? ORDER BY h.created_at DESC');
$history->execute([$parcelId]);
$history = $history->fetchAll();

$photos = $pdo->prepare('SELECT * FROM delivery_photos WHERE parcel_id = ? ORDER BY created_at DESC');
$photos->execute([$parcelId]);
$photos = $photos->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<div class="panel">
    <h2>Parcel Details</h2>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <p><strong>Tracking:</strong> <?= htmlspecialchars($parcel['tracking_number'], ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Recipient:</strong> <?= htmlspecialchars($parcel['recipient_name'], ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Address:</strong> <?= htmlspecialchars($parcel['recipient_address'], ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong>Assigned Rider:</strong> <?= htmlspecialchars($parcel['rider_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></p>
<?php if (!empty($parcel['assigned_rider_id'])): ?>
    <p><strong>Delivery Coordinates:</strong>
        <?= $parcel['recipient_latitude'] !== null ? htmlspecialchars($parcel['recipient_latitude'] . ', ' . $parcel['recipient_longitude'], ENT_QUOTES, 'UTF-8') : 'Not geocoded yet' ?>
    </p>
<?php endif; ?>
</div>
<div class="panel">
    <h2>Update Delivery</h2>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= csrf_field() ?>
        <label>Status<select name="status">
            <option value="Pending" <?= $parcel['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
            <option value="Out for Delivery" <?= $parcel['status'] === 'Out for Delivery' ? 'selected' : '' ?>>Out for Delivery</option>
            <option value="Delivered" <?= $parcel['status'] === 'Delivered' ? 'selected' : '' ?>>Delivered</option>
            <option value="Failed Delivery" <?= $parcel['status'] === 'Failed Delivery' ? 'selected' : '' ?>>Failed Delivery</option>
        </select></label>
        <label>Proof Photo<input type="file" name="proof_photo" accept="image/*"></label>
        <label style="grid-column: 1 / -1;">Remarks<textarea name="remarks" rows="3"></textarea></label>
        <div class="form-actions" style="grid-column: 1 / -1;"><button class="btn btn-primary" type="submit">Save Update</button></div>
    </form>
</div>
<div class="panel">
    <h2>Status History</h2>
    <div class="activity-list">
        <?php foreach ($history as $item): ?>
            <div class="activity-item">
                <strong><?= htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8') ?></strong>
                <div><?= htmlspecialchars($item['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <small><?= htmlspecialchars($item['created_at'], ENT_QUOTES, 'UTF-8') ?> by <?= htmlspecialchars($item['full_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="panel">
    <h2>Proof Photos</h2>
    <?php foreach ($photos as $photo): ?>
        <img src="<?= htmlspecialchars($photo['file_path'], ENT_QUOTES, 'UTF-8') ?>" alt="Delivery proof" style="max-width:200px; margin-right:10px; margin-bottom:10px; border-radius:10px;">
    <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
