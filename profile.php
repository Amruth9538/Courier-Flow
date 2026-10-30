<?php
require_once __DIR__ . '/config.php';
require_auth();

if (is_admin()) {
    redirect('dashboard.php');
}

$rider = ensure_rider_profile();
if (!$rider) {
    redirect('rider-dashboard.php');
}

$profile = $pdo->prepare('SELECT u.full_name, u.phone, r.* FROM users u LEFT JOIN riders r ON r.user_id = u.id WHERE u.id = ?');
$profile->execute([$_SESSION['user_id']]);
$profile = $profile->fetch();
if (!$profile) {
    redirect('rider-dashboard.php');
}

$rider = $profile;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // 1. 获取并过滤输入字段
    $full_name = sanitize_string($_POST['full_name'] ?? '');
    $phone     = sanitize_string($_POST['phone'] ?? '');
    $vehicle   = sanitize_string($_POST['vehicle_type'] ?? '');
    $license   = sanitize_string($_POST['license_number'] ?? '');

    // 2. 更新 users 表 (Full Name & Phone)
    $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')
        ->execute([$full_name, $phone, $_SESSION['user_id']]);

    // 3. 更新 riders 表 (Vehicle Type & License Number)
    $pdo->prepare('UPDATE riders SET vehicle_type = ?, license_number = ? WHERE id = ?')
        ->execute([$vehicle, $license, $rider['id']]);

    $message = 'Profile updated.';

    // 4. 重新获取最新的 Profile 数据
    $profile = $pdo->prepare('SELECT u.full_name, u.phone, r.* FROM users u LEFT JOIN riders r ON r.user_id = u.id WHERE u.id = ?');
    $profile->execute([$_SESSION['user_id']]);
    $rider = $profile->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="panel">
    <h2>Profile</h2>
    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" class="form-grid">
        <?= csrf_field() ?>

        <label>
            Full Name
            <input type="text" name="full_name" value="<?= htmlspecialchars($rider['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
        </label>

        <label>
            Phone
            <input type="text" name="phone" value="<?= htmlspecialchars($rider['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
        </label>

        <label>
            Vehicle Type
            <input type="text" name="vehicle_type" value="<?= htmlspecialchars($rider['vehicle_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <label>
            License Number
            <input type="text" name="license_number" value="<?= htmlspecialchars($rider['license_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <div class="form-actions" style="grid-column: 1 / -1;">
            <button class="btn btn-primary" type="submit">Save Profile</button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>