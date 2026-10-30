<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([
        $_SESSION['user_id'],
        'Logout',
        'User signed out.'
    ]);
}

session_destroy();
redirect('login.php');
