
<?php
require_once __DIR__ . '/config.php';

$message = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        $name = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $vehicle = trim((string)($_POST['vehicle_type'] ?? ''));
        $license = trim((string)($_POST['license_number'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            $message = 'Please fill in all required fields.';
        } elseif (strlen($name) > 150 || strlen($username) > 100) {
            $message = 'Name or username is too long.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $message = 'Please enter a valid email address.';
        } elseif (strlen($phone) > 30 || strlen($vehicle) > 50 || strlen($license) > 50) {
            $message = 'One of your details is too long.';
        } elseif (strlen($password) < 8) {
            $message = 'Password must contain at least 8 characters.';
        } elseif ($password !== $confirm) {
            $message = 'Passwords do not match.';
        } else {
            $check = $pdo->prepare(
                'SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1'
            );
            $check->execute([$email, $username]);

            if ($check->fetch()) {
                $message = 'Email or username is already registered.';
            } else {
                $pdo->beginTransaction();

                // Create inactive rider login account
                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (username, full_name, email, password, role, phone, status)
                    VALUES (?, ?, ?, ?, 'rider', ?, 'inactive')
                ");

                $stmt->execute([
                    $username,
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $phone !== '' ? $phone : null
                ]);

                $userId = (int)$pdo->lastInsertId();

                // Create rider profile
                // Rider remains offline and unavailable until approval.
                $stmt = $pdo->prepare("
                    INSERT INTO riders
                    (user_id, vehicle_type, license_number, is_online, is_available)
                    VALUES (?, ?, ?, 0, 0)
                ");

                $stmt->execute([
                    $userId,
                    $vehicle !== '' ? $vehicle : null,
                    $license !== '' ? $license : null
                ]);

                // Record registration activity
                $stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action, details)
                    VALUES (?, ?, ?)
                ");

                $stmt->execute([
                    $userId,
                    'Rider Registration',
                    'New rider registration submitted. Waiting for admin approval.'
                ]);

                $pdo->commit();

                $success = 'Registration submitted successfully! Please wait for admin approval.';
            }
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
$message = 'Database Error: ' . $e->getMessage();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = 'Your session expired. Refresh the page and try again.';

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = 'Something went wrong. Please try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Registration | CourierFlow</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        body.auth-page {
            background: linear-gradient(135deg, #07101f, #102341, #080d1b);
            min-height: 100vh;
        }

        .auth-card {
            animation: slideIn 0.8s ease both;
            border: 1px solid rgba(66, 217, 255, 0.35);
            box-shadow: 0 0 30px rgba(66, 217, 255, 0.12);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(35px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-card__header h1 {
            color: #42d9ff;
        }

        .auth-form input {
            transition: 0.3s ease;
        }

        .auth-form input:focus {
            border-color: #42d9ff;
            box-shadow: 0 0 12px rgba(66, 217, 255, 0.25);
            outline: none;
        }

        .auth-form button {
            transition: 0.3s ease;
        }

        .auth-form button:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(66, 217, 255, 0.3);
        }

        .approval-note {
            background: rgba(66, 217, 255, 0.08);
            border: 1px solid rgba(66, 217, 255, 0.3);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 20px;
            color: #42d9ff;
            line-height: 1.6;
            font-size: 14px;
        }

        .auth-links {
            text-align: center;
            margin-top: 22px;
            line-height: 2;
        }

        .auth-links a {
            color: #42d9ff;
            text-decoration: none;
        }

        .auth-links a:hover {
            text-decoration: underline;
        }

        @media (prefers-reduced-motion: reduce) {
            .auth-card {
                animation: none;
            }
        }
    </style>
</head>

<body class="auth-page">

<div class="auth-card">

    <div class="auth-card__header">
        <h1>🛵 CourierFlow</h1>
        <p>Rider Registration Portal</p>
    </div>

    <div class="approval-note">
        <strong>🔐 Admin Approval Required</strong><br>
        Submit your details below. Your account will be activated
        after the administrator approves your registration.
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="auth-form">

        <?= csrf_field() ?>

        <label>
            Full Name *
            <input type="text" name="full_name" maxlength="150" required>
        </label>

        <label>
            Username *
            <input type="text" name="username" maxlength="100" required>
        </label>

        <label>
            Email Address *
            <input type="email" name="email" maxlength="150" required>
        </label>

        <label>
            Phone Number
            <input type="tel" name="phone" maxlength="30">
        </label>

        <label>
            Vehicle Type
            <input type="text" name="vehicle_type" maxlength="50"
                   placeholder="Bike / Scooter">
        </label>

        <label>
            Driving Licence Number
            <input type="text" name="license_number" maxlength="50">
        </label>

        <label>
            Password *
            <input type="password" name="password" minlength="8" required>
        </label>

        <label>
            Confirm Password *
            <input type="password" name="confirm_password" minlength="8" required>
        </label>

        <button type="submit" class="btn btn-primary">
            Submit Rider Registration
        </button>

    </form>

    <div class="auth-links">
        <p>Already have a rider account?</p>
        <a href="login.php?role=rider">Rider Login</a>

        <p>
            <a href="index.php">← Back to Welcome Page</a>
        </p>
    </div>

</div>

</body>
</html>
