<?php
require_once __DIR__ . '/config.php';

if (!is_logged_in()) {
    redirect('login.php');
}

redirect(is_admin() ? 'dashboard.php' : 'rider-dashboard.php');
