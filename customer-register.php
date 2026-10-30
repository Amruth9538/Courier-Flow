<?php
require_once __DIR__ . '/config.php';

$message = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        verify_csrf();

        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            $message = 'Please fill in all required fields.';
        } elseif (strlen($name) > 150) {
            $message = 'Name is too long.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $message = 'Please enter a valid email address.';
        } elseif (strlen($phone) > 30) {
            $message = 'Phone number is too long.';
        } elseif (strlen($password) < 8) {
            $message = 'Password must contain at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $message = 'Passwords do not match.';
        } else {

            // Check whether email already exists
            $stmt = $pdo->prepare(
                'SELECT id FROM users WHERE email = ? LIMIT 1'
            );
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $message = 'This email is already registered.';
            } else {

                // Securely hash the password
                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Create customer account
                $stmt = $pdo->prepare("
                    INSERT INTO users
                        (full_name, email, phone, password, role, status)
                    VALUES
                        (?, ?, ?, ?, 'customer', 'active')
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $hashedPassword
                ]);

                $success = 'Customer account created successfully! You can now log in.';
            }
        }

    } catch (PDOException $e) {
        $message = 'Unable to create account. Please try again.';
    } catch (RuntimeException $e) {
        $message = 'Your session expired. Refresh the page and try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Registration | CourierFlow</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="auth-page">

<div class="auth-card">

    <div class="auth-card__header">
        <h1>🚚 CourierFlow</h1>
        <p>Create Your Customer Account</p>
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
            Full Name
            <input
                type="text"
                name="full_name"
                maxlength="150"
                required
            >
        </label>

        <label>
            Email Address
            <input
                type="email"
                name="email"
                maxlength="150"
                required
            >
        </label>

        <label>
            Phone Number
            <input
                type="tel"
                name="phone"
                maxlength="30"
            >
        </label>

        <label>
            Password
            <input
                type="password"
                name="password"
                minlength="8"
                required
            >
        </label>

        <label>
            Confirm Password
            <input
                type="password"
                name="confirm_password"
                minlength="8"
                required
            >
        </label>

        <button type="submit" class="btn btn-primary">
            Create Customer Account
        </button>

    </form>

    <div style="text-align:center; margin-top:20px;">
        <p>Already have an account?</p>

        <a href="login.php">
            Login Here
        </a>
    </div>

</div>

</body>
</html>