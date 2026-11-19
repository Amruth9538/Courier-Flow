<?php
require_once '../config.php';

// 验证登录状态与 Admin 权限
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_rider') {
    try {
        verify_csrf();

        $fullName = sanitize_string($_POST['full_name'] ?? '');
        $username = sanitize_string($_POST['username'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $phone = sanitize_string($_POST['phone'] ?? '');
        $vehicleType = sanitize_string($_POST['vehicle_type'] ?? '');
        $licenseNumber = sanitize_string($_POST['license_number'] ?? '');

        if ($fullName === '' || $username === '' || !$email || $password === '') {
            throw new RuntimeException('Please enter Full Name, Username, Email, and Password.');
        }

        $pdo->beginTransaction();
        $user = $pdo->prepare("INSERT INTO users (username, full_name, email, password, role, phone, status) VALUES (?, ?, ?, ?, 'rider', ?, 'active')");
        $user->execute([$username, $fullName, $email, password_hash($password, PASSWORD_DEFAULT), $phone ?: null]);
        $userId = (int) $pdo->lastInsertId();

        $rider = $pdo->prepare('INSERT INTO riders (user_id, vehicle_type, license_number, is_online, is_available) VALUES (?, ?, ?, 0, 1)');
        $rider->execute([$userId, $vehicleType ?: null, $licenseNumber ?: null]);
        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
            $_SESSION['user_id'],
            'Rider Created',
            'Created rider account for ' . $fullName,
        ]);
        $pdo->commit();
        $message = 'Rider account created. The rider can now sign in with their email and password.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof PDOException && $e->getCode() === '23000'
            ? 'That username or email is already in use.'
            : $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_rider') {
    try {
        verify_csrf();

        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Invalid rider ID.');
        }

        $riderCheck = $pdo->prepare('SELECT u.full_name FROM users u WHERE u.id = ? AND u.role = ?');
        $riderCheck->execute([$userId, 'rider']);
        $riderData = $riderCheck->fetch();
        if (!$riderData) {
            throw new RuntimeException('Rider not found.');
        }

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM users WHERE id = ? AND role = ?')->execute([$userId, 'rider']);
        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
            $_SESSION['user_id'],
            'Rider Deleted',
            'Deleted rider account: ' . $riderData['full_name'],
        ]);
        $pdo->commit();
        $message = 'Rider account deleted successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// 查询所有 Rider 数据
$riders = $pdo->query("
    SELECT u.id as user_id, u.full_name, u.username, u.phone, r.vehicle_type, r.license_number
    FROM riders r
    JOIN users u ON r.user_id = u.id
    WHERE u.role = 'rider'
    ORDER BY u.id ASC
")->fetchAll();

require_once '../includes/header.php';
?>
<style>
/* CourierFlow Riders Page Background */

body {
    background-image: url('../assets/images/courierflow-bg.png') !important;
    background-size: cover !important;
    background-position: center !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

/* Show the background image */
.app-shell {
    background: transparent !important;
}

/* Rounded dashboard panel */
.app-shell > .content {
    background: rgba(238, 247, 255, 0.88) !important;
    border-radius: 20px !important;
}

/* Make the Admin Console header blend into the dashboard */
.app-shell > .content > .topbar {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}
/* ==========================================
   COURIERFLOW RIDERS PAGE FINAL DESIGN
========================================== */

/* Unified dashboard container */
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
    margin: 0 !important;
    padding: 8px 12px 20px !important;
}

/* White rider cards */
.app-shell > .content .panel.card {
    background: rgba(255, 255, 255, 0.97) !important;
    border-radius: 16px !important;
    border: 1px solid #e0edff !important;
    padding: 22px !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.06);
}
</style>

<style>
.main-content {
    flex: 1;
    padding: 30px;
    background-color: #f8fafc;
    min-height: 100vh;
    box-sizing: border-box;
}

.card {
    background: #ffffff;
    border-radius: 10px;
    padding: 24px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.card h3 {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin-top: 0;
    margin-bottom: 20px;
}

.data-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.data-table th {
    background-color: #f8fafc;
    color: #475569;
    font-size: 13px;
    font-weight: 600;
    padding: 12px 16px;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
}

.data-table td {
    padding: 14px 16px;
    font-size: 14px;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
}

.data-table tbody tr:hover {
    background-color: #f8fafc;
}

.rider-form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.rider-form-grid label {
    display: flex;
    flex-direction: column;
    gap: 7px;
    font-weight: 600;
    color: #334155;
}

.rider-form-grid input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #dfe7f1;
    border-radius: 10px;
    font: inherit;
}

@media (max-width: 900px) {
    .rider-form-grid { grid-template-columns: 1fr; }
}
</style>

<div class="main-content">
    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 24px;">
        <h3>Add New Rider</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_rider">
            <div class="rider-form-grid">
                <label>Full Name<input type="text" name="full_name" required></label>
                <label>Username<input type="text" name="username" required></label>
                <label>Email<input type="email" name="email" required></label>
                <label>Password<input type="password" name="password" required></label>
                <label>Phone<input type="text" name="phone"></label>
                <label>Vehicle Type<input type="text" name="vehicle_type" placeholder="e.g. Scooter"></label>
                <label>License Number<input type="text" name="license_number"></label>
            </div>
            <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                <button type="submit" class="btn btn-primary">Add Rider</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Rider List</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Vehicle</th>
                    <th>License</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($riders)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #94a3b8; padding: 20px;">No riders found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($riders as $r): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($r['username']) ?></td>
                            <td><?= htmlspecialchars($r['phone'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['vehicle_type'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($r['license_number'] ?: '-') ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete rider <?= htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8') ?>? This action cannot be undone.');" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_rider">
                                    <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
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

<?php require_once '../includes/footer.php'; ?>
