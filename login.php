<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    $role = $_SESSION['role'] ?? '';

    if ($role === 'admin') {
        redirect('dashboard.php');
    } elseif ($role === 'rider') {
        redirect('rider-dashboard.php');
    } elseif ($role === 'customer') {
        redirect('customer-dashboard.php');
    }
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    if ($message === '') {
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

if (
    $user &&
    $user['status'] === 'active' &&
    password_verify($password, $user['password'])
) {            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];

            $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
                $user['id'],
                'Login',
                'User signed in to the system.'
            ]);

            if ($user['role'] === 'admin') {
    redirect('dashboard.php');
} elseif ($user['role'] === 'rider') {
    redirect('rider-dashboard.php');
} elseif ($user['role'] === 'customer') {
    redirect('customer-dashboard.php');
}
        } else {
            $message = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Delivery Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-card__header">
            <h1>🚚 CourierFlow</h1>
            <p>Smart Parcel Delivery & Tracking System</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <?= csrf_field() ?>
            <label>
                Email
                <input type="email" name="email" required>
            </label>
            <label>
                Password
                <input type="password" name="password" required>
            </label>
            <button type="submit" class="btn btn-primary">Login</button>
        </form>

        <div class="auth-footer" style="text-align: left; font-size: 13px; color: #64748b; line-height: 1.5; margin-top: 16px;">
            <p style="margin: 0; font-weight: 700; color: #0f172a; font-size: 14px;"><strong>Demo accounts</strong></p>
            <p style="margin: 4px 0 0 0;"><strong>Admin:</strong> admin@example.com / password</p>
            <p style="margin: 2px 0 0 0;"><strong>Rider:</strong> rider2@gmail.com / 12121212</p>
        </div>
    </div>
</body>
</html>