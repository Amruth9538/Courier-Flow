<?php
require_once __DIR__ . '/config.php';
require_auth();

if (!is_rider()) {
    redirect(is_admin() ? 'dashboard.php' : 'customer-dashboard.php');
}

$riderProfile = ensure_rider_profile();
if (!$riderProfile) {
    redirect('login.php');
}

$parcelId = (int) ($_GET['id'] ?? 0);
$parcel = $pdo->prepare('SELECT p.*, u.full_name AS rider_name FROM parcels p LEFT JOIN riders r ON r.id = p.assigned_rider_id LEFT JOIN users u ON u.id = r.user_id WHERE p.id = ? AND p.assigned_rider_id = ?');
$parcel->execute([$parcelId, (int) $riderProfile['id']]);
$parcel = $parcel->fetch();

if (!$parcel) {
    redirect('rider-dashboard.php');
}

$message = '';
$error = '';
if (!empty($_SESSION['parcel_detail_message'])) {
    $message = (string) $_SESSION['parcel_detail_message'];
    unset($_SESSION['parcel_detail_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $statusInput = $_POST['status'] ?? '';
        if (!is_string($statusInput)) {
            throw new RuntimeException('Select a valid parcel status.');
        }
        $status = trim($statusInput);
        $remarks = sanitize_string($_POST['remarks'] ?? '');
        if (mb_strlen($remarks) > 2000) {
            throw new RuntimeException('Remarks must not exceed 2000 characters.');
        }

        $pdo->beginTransaction();
        $currentParcel = $pdo->prepare("
            SELECT status, customer_id, tracking_number
            FROM parcels
            WHERE id = ? AND assigned_rider_id = ?
            FOR UPDATE
        ");
        $currentParcel->execute([$parcelId, (int) $riderProfile['id']]);
        $current = $currentParcel->fetch();
        if (!$current) {
            throw new RuntimeException('This parcel is not assigned to you.');
        }
        if (!can_transition_parcel_status((string) $current['status'], $status)
            || $current['status'] === $status) {
            throw new RuntimeException('Select only the next allowed parcel status.');
        }

        $otherActive = $pdo->prepare("
            SELECT COUNT(*)
            FROM parcels
            WHERE assigned_rider_id = ?
              AND id <> ?
              AND status NOT IN ('Delivered', 'Cancelled')
        ");
        $otherActive->execute([(int) $riderProfile['id'], $parcelId]);
        if ((int) $otherActive->fetchColumn() > 0) {
            throw new RuntimeException('Complete your current parcel before updating another assignment.');
        }

        $photoPath = '';
        if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['proof_photo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The proof photo could not be uploaded.');
            }
            if ($_FILES['proof_photo']['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Image must be 2MB or smaller.');
            }

            $targetDir = __DIR__ . '/uploads/proofs/';
            if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                throw new RuntimeException('The proof photo directory could not be created.');
            }
            $fileName = time() . '_' . bin2hex(random_bytes(6)) . '.' . strtolower(pathinfo($_FILES['proof_photo']['name'], PATHINFO_EXTENSION));
            $targetPath = $targetDir . $fileName;
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (!in_array($_FILES['proof_photo']['type'], $allowed, true)) {
                throw new RuntimeException('Only JPEG, PNG, and WebP images are allowed.');
            }
            if (!move_uploaded_file($_FILES['proof_photo']['tmp_name'], $targetPath)) {
                throw new RuntimeException('The proof photo could not be saved.');
            }
            $photoPath = 'uploads/proofs/' . $fileName;
            $pdo->prepare('INSERT INTO delivery_photos (parcel_id, rider_id, file_path) VALUES (?, ?, ?)')
                ->execute([$parcelId, (int) $riderProfile['id'], $photoPath]);
        }

        $pdo->prepare('UPDATE parcels SET status = ? WHERE id = ? AND assigned_rider_id = ?')
            ->execute([$status, $parcelId, (int) $riderProfile['id']]);
        $pdo->prepare('INSERT INTO parcel_status_history (parcel_id, status, remarks, photo_path, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$parcelId, $status, $remarks, $photoPath ?: null, $_SESSION['user_id']]);

        $activeCountStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM parcels
            WHERE assigned_rider_id = ?
              AND status NOT IN ('Delivered', 'Cancelled')
        ");
        $activeCountStmt->execute([(int) $riderProfile['id']]);
        $hasActiveParcel = (int) $activeCountStmt->fetchColumn() > 0;
        $isOnline = !empty($riderProfile['is_online']);
        $pdo->prepare('UPDATE riders SET is_available = ? WHERE id = ?')
            ->execute([$hasActiveParcel || !$isOnline ? 0 : 1, (int) $riderProfile['id']]);

        if (!$hasActiveParcel && $isOnline) {
            $waiting = $pdo->query("
                SELECT id
                FROM parcels
                WHERE assigned_rider_id IS NULL AND status = 'Pending'
                ORDER BY created_at ASC, id ASC
                LIMIT 1
                FOR UPDATE
            ")->fetchColumn();
            if ($waiting) {
                $assign = $pdo->prepare("
                    UPDATE parcels
                    SET assigned_rider_id = ?
                    WHERE id = ? AND assigned_rider_id IS NULL AND status = 'Pending'
                ");
                $assign->execute([(int) $riderProfile['id'], (int) $waiting]);
                if ($assign->rowCount() > 0) {
                    $pdo->prepare('UPDATE riders SET is_available = 0 WHERE id = ?')
                        ->execute([(int) $riderProfile['id']]);
                    $waitingParcel = $pdo->prepare('SELECT customer_id, tracking_number FROM parcels WHERE id = ?');
                    $waitingParcel->execute([(int) $waiting]);
                    $waitingDetails = $waitingParcel->fetch();
                    if ($waitingDetails && !empty($waitingDetails['customer_id'])) {
                        $pdo->prepare("
                            INSERT INTO notifications (user_id, parcel_id, title, message, is_read)
                            VALUES (?, ?, 'Rider Assigned', ?, 0)
                        ")->execute([
                            (int) $waitingDetails['customer_id'],
                            (int) $waiting,
                            'Your parcel ' . $waitingDetails['tracking_number'] . ' has been assigned to a delivery rider.'
                        ]);
                    }
                }
            }
        }

        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')
            ->execute([
                $_SESSION['user_id'],
                'Parcel Update',
                'Parcel ' . $current['tracking_number'] . ' updated to ' . $status
            ]);
        if (!empty($current['customer_id'])) {
            $notificationMessages = [
                'In Transit' => 'Your parcel is on the way.',
                'Out for Delivery' => 'Your parcel is out for delivery.',
                'Delivered' => 'Your parcel has been delivered successfully.',
                'Failed Delivery' => 'A delivery attempt was unsuccessful.',
                'Cancelled' => 'Your parcel has been cancelled.',
            ];
            if (isset($notificationMessages[$status])) {
                $pdo->prepare("
                    INSERT INTO notifications (user_id, parcel_id, title, message, is_read)
                    VALUES (?, ?, 'Parcel Status Updated', ?, 0)
                ")->execute([
                    (int) $current['customer_id'],
                    $parcelId,
                    $notificationMessages[$status] . ' Tracking Number: ' . $current['tracking_number']
                ]);
            }
        }

        $pdo->commit();
        $_SESSION['parcel_detail_message'] = 'Parcel updated.';
        redirect('parcel-detail.php?id=' . $parcelId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
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
    <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
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
        <label>Status<select name="status" required>
            <option value="">Select next status</option>
            <?php foreach (['In Transit', 'Out for Delivery', 'Delivered', 'Failed Delivery', 'Cancelled'] as $nextStatus): ?>
                <?php if ($nextStatus !== $parcel['status'] && can_transition_parcel_status((string) $parcel['status'], $nextStatus)): ?>
                    <option value="<?= htmlspecialchars($nextStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($nextStatus, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select></label>
        <label>Proof Photo<input type="file" name="proof_photo" accept="image/*"></label>
        <label style="grid-column: 1 / -1;">Remarks<textarea name="remarks" rows="3"></textarea></label>
        <div class="form-actions" style="grid-column: 1 / -1;"><button class="btn btn-primary" type="submit" <?= in_array($parcel['status'], ['Delivered', 'Cancelled'], true) ? 'disabled' : '' ?>>Save Update</button></div>
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
