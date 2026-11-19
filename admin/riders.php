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

        $fullName = trim(sanitize_string($_POST['full_name'] ?? ''));
        $username = sanitize_string($_POST['username'] ?? '');
        $emailInput = trim((string) ($_POST['email'] ?? ''));
        $email = filter_var($emailInput, FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $vehicleType = sanitize_string($_POST['vehicle_type'] ?? '');
        $licenseNumber = sanitize_string($_POST['license_number'] ?? '');

        if ($fullName === '' || $username === '' || !$email || $password === '' || $phone === '' || $vehicleType === '' || $licenseNumber === '') {
            throw new RuntimeException('Please fill in every field before adding the rider.');
        }
        if (strlen($password) < 8 || !preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new RuntimeException('Password must be at least 8 characters and include at least one special character.');
        }
        if (!preg_match('/@[^@\s]+\.com$/i', $emailInput)) {
            throw new RuntimeException('Enter a valid email address that includes @ and ends with .com.');
        }
        if (!preg_match("/^[\\p{L}][\\p{L} .'-]*$/u", $fullName)) {
            throw new RuntimeException('Full Name may contain letters, spaces, apostrophes, periods, and hyphens only.');
        }
        if (!preg_match("/^[\\p{L}][\\p{L}._-]*$/u", $username)) {
            throw new RuntimeException('Username must start with a letter and contain only letters, periods, underscores, and hyphens.');
        }
        if (!preg_match('/^[0-9]{10}$/', $phone)) {
            throw new RuntimeException('Phone must contain exactly 10 digits.');
        }
        if (!preg_match("/^[\\p{L}][\\p{L} -]*$/u", $vehicleType)) {
            throw new RuntimeException('Vehicle Type may contain letters, spaces, and hyphens only.');
        }

        $pdo->beginTransaction();
        $user = $pdo->prepare("INSERT INTO users (username, full_name, email, password, role, phone, status) VALUES (?, ?, ?, ?, 'rider', ?, 'active')");
        $user->execute([$username, $fullName, $email, password_hash($password, PASSWORD_DEFAULT), $phone ?: null]);
        $userId = (int) $pdo->lastInsertId();

        $rider = $pdo->prepare("INSERT INTO riders (user_id, vehicle_type, license_number, is_online, is_available, approval_status) VALUES (?, ?, ?, 0, 1, 'approved')");
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
// Approve or Reject Rider Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    in_array($_POST['action'] ?? '', ['approve_rider', 'reject_rider'], true)) {

    try {
        verify_csrf();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $action = $_POST['action'];

        if ($userId <= 0) {
            throw new RuntimeException('Invalid rider ID.');
        }

        $check = $pdo->prepare("
            SELECT u.id, u.full_name
            FROM users u
            JOIN riders r ON r.user_id = u.id
            WHERE u.id = ? AND u.role = 'rider'
        ");
        $check->execute([$userId]);
        $rider = $check->fetch();

        if (!$rider) {
            throw new RuntimeException('Rider not found.');
        }

        $pdo->beginTransaction();

        if ($action === 'approve_rider') {
            $pdo->prepare("
                UPDATE riders
SET approval_status = 'approved',
    is_available = 1
WHERE user_id = ?
            ")->execute([$userId]);

            $pdo->prepare("
                UPDATE users SET status = 'active'
                WHERE id = ? AND role = 'rider'
            ")->execute([$userId]);

            $logAction = 'Rider Approved';
            $message = 'Rider approved successfully.';
        } else {
            $pdo->prepare("
                UPDATE riders SET approval_status = 'rejected'
                WHERE user_id = ?
            ")->execute([$userId]);

            $pdo->prepare("
                UPDATE users SET status = 'inactive'
                WHERE id = ? AND role = 'rider'
            ")->execute([$userId]);

            $logAction = 'Rider Rejected';
            $message = 'Rider application rejected.';
        }

        $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, details)
            VALUES (?, ?, ?)
        ")->execute([
            $_SESSION['user_id'],
            $logAction,
            $rider['full_name']
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// 查询所有 Rider 数据
$riders = $pdo->query("
    SELECT
        u.id AS user_id,
        u.full_name,
        u.username,
        u.phone,
        u.status,
        r.vehicle_type,
        r.license_number,
        r.approval_status
    FROM riders r
    JOIN users u ON r.user_id = u.id
    WHERE u.role = 'rider'
    ORDER BY
        CASE r.approval_status
            WHEN 'pending' THEN 0
            WHEN 'approved' THEN 1
            ELSE 2
        END,
        u.id ASC
")->fetchAll();

require_once '../includes/header.php';
?>
<style>
/* CourierFlow Riders - Clean Professional Design */

body {
    background: #f1f5f9 !important;
    background-image: none !important;
}

.app-shell {
    background: #f1f5f9 !important;
}

.app-shell > .content {
    background: #f1f5f9 !important;
    border-radius: 20px !important;
}

.app-shell > .content > .topbar {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}

.main-content {
    background: #f1f5f9 !important;
}

.card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.05) !important;
}

.card h3 {
    color: #1e3a8a !important;
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
        <p>Enter the rider's real account details. Examples below are placeholders only and will not be submitted automatically.</p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_rider">
            <div class="rider-form-grid">
                <label>Full Name
                    <input type="text" name="full_name" maxlength="150" autocomplete="name" placeholder="e.g. Rahul Sharma" aria-describedby="rider-name-message" required>
                    <small id="rider-name-message" role="status" aria-live="polite" style="display: none; color: #b91c1c;">Please enter a name using letters only; numbers are not allowed.</small>
                </label>
                <label>Username
                    <input type="text" name="username" maxlength="100" autocomplete="username" placeholder="e.g. rahul.sharma" pattern="[A-Za-z](?:[A-Za-z]|[._]|-)*" title="Start with a letter; use letters, periods, underscores, or hyphens only." aria-describedby="rider-username-message" required>
                    <small id="rider-username-message" role="status" aria-live="polite" style="display: none; color: #b91c1c;">Please enter a username using letters only; numbers are not allowed.</small>
                </label>
                <label>Email
                    <input type="email" name="email" maxlength="150" autocomplete="email" placeholder="e.g. rahul@example.com" pattern=".+@.+[.]com" title="Enter a valid email address that includes @ and ends with .com." aria-describedby="rider-email-message" required>
                    <small id="rider-email-message" role="status" aria-live="polite" style="display: none; color: #b91c1c;">Enter a valid email address that includes @ and ends with .com.</small>
                </label>
                <label>Password
                    <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="8+ characters and one special character" pattern="(?=.*[^A-Za-z0-9]).{8,72}" title="Use at least 8 characters and include at least one special character." aria-describedby="rider-password-message" required>
                    <small id="rider-password-message" role="status" aria-live="polite" style="display: none; color: #b91c1c;">Password must be at least 8 characters and include at least one special character.</small>
                </label>
                <label>Phone
                    <input type="tel" name="phone" maxlength="30" minlength="10" inputmode="numeric" pattern="[0-9]{10}" autocomplete="tel-national" placeholder="e.g. 9876543210" title="Enter exactly 10 digits." aria-describedby="rider-phone-message" required>
                    <small id="rider-phone-message" role="status" aria-live="polite" style="display: none; color: #b91c1c;">Phone number can contain digits only; letters are not allowed.</small>
                </label>
                <label>Vehicle Type
                    <input type="text" name="vehicle_type" maxlength="50" placeholder="e.g. Electric Scooter" pattern="[A-Za-z](?:[A-Za-z]| |-)*" title="Use letters, spaces, and hyphens only." aria-describedby="rider-vehicle-message" required>
                    <small id="rider-vehicle-message" role="status" aria-live="polite" style="display: none; color: #b91c1c;">Please enter a vehicle type using letters only; numbers are not allowed.</small>
                </label>
                <label>License Number
                    <input type="text" name="license_number" maxlength="100" placeholder="e.g. KA-01-2024-001234" required>
                </label>
            </div>
            <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                <button type="submit" class="btn btn-primary" disabled>Add Rider</button>
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
<th>Approval Status</th>
<th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($riders)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #94a3b8; padding: 20px;">No riders found.</td>
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
    <?php if ($r['approval_status'] === 'pending'): ?>
        <span style="color: #d97706; font-weight: bold;">Pending</span>
    <?php elseif ($r['approval_status'] === 'approved'): ?>
        <span style="color: #16a34a; font-weight: bold;">Approved</span>
    <?php else: ?>
        <span style="color: #dc2626; font-weight: bold;">Rejected</span>
    <?php endif; ?>
</td>
                            <td>
                                <?php if ($r['approval_status'] === 'pending'): ?>

    <form method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="approve_rider">
        <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('Approve this rider?');">
            Approve
        </button>
    </form>

    <form method="POST" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject_rider">
        <input type="hidden" name="user_id" value="<?= (int) $r['user_id'] ?>">
        <button type="submit" class="btn btn-danger"
                onclick="return confirm('Reject this rider?');">
            Reject
        </button>
    </form>

<?php endif; ?>
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

<script>
const riderNameInput = document.querySelector('input[name="full_name"]');
const riderUsernameInput = document.querySelector('input[name="username"]');
const riderPhoneInput = document.querySelector('input[name="phone"]');
const riderVehicleInput = document.querySelector('input[name="vehicle_type"]');
const riderNameMessage = document.getElementById('rider-name-message');
const riderUsernameMessage = document.getElementById('rider-username-message');
const riderPhoneMessage = document.getElementById('rider-phone-message');
const riderVehicleMessage = document.getElementById('rider-vehicle-message');
const riderEmailInput = document.querySelector('input[name="email"]');
const riderEmailMessage = document.getElementById('rider-email-message');
const riderPasswordInput = document.querySelector('input[name="password"]');
const riderPasswordMessage = document.getElementById('rider-password-message');
const riderForm = riderNameInput.form;
const addRiderButton = riderForm.querySelector('button[type="submit"]');

function updateAddRiderButton() {
    addRiderButton.disabled = !riderForm.checkValidity()
        || Array.from(riderForm.querySelectorAll('input[required]')).some(input => input.value.trim() === '');
}

riderForm.addEventListener('input', updateAddRiderButton);
updateAddRiderButton();

riderNameInput.addEventListener('input', () => {
    const containsNumber = /\d/.test(riderNameInput.value);
    riderNameInput.value = riderNameInput.value.replace(/[^\p{L} .'-]/gu, '');
    riderNameMessage.style.display = containsNumber ? 'block' : 'none';
});

riderUsernameInput.addEventListener('input', () => {
    const containsNumber = /\d/.test(riderUsernameInput.value);
    riderUsernameInput.value = riderUsernameInput.value.replace(/[^\p{L}._-]/gu, '');
    riderUsernameMessage.style.display = containsNumber ? 'block' : 'none';
});

riderPhoneInput.addEventListener('input', () => {
    const containsNonDigit = /\D/.test(riderPhoneInput.value);
    riderPhoneInput.value = riderPhoneInput.value.replace(/\D/g, '').slice(0, 10);
    riderPhoneMessage.style.display = containsNonDigit ? 'block' : 'none';
});

riderVehicleInput.addEventListener('input', () => {
    const containsNumber = /\d/.test(riderVehicleInput.value);
    riderVehicleInput.value = riderVehicleInput.value.replace(/[^\p{L} -]/gu, '');
    riderVehicleMessage.style.display = containsNumber ? 'block' : 'none';
});

riderEmailInput.addEventListener('input', () => {
    const email = riderEmailInput.value.trim();
    const hasValidShape = /^[^\s@]+@[^\s@]+\.com$/i.test(email);
    riderEmailMessage.style.display = email !== '' && !hasValidShape ? 'block' : 'none';
});

riderPasswordInput.addEventListener('input', () => {
    const password = riderPasswordInput.value;
    const isValid = password.length >= 8 && /[^A-Za-z0-9]/.test(password);
    riderPasswordMessage.style.display = password !== '' && !isValid ? 'block' : 'none';
});
</script>

<?php require_once '../includes/footer.php'; ?>
