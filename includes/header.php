<?php
$assetBase = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? '../' : '';
$bodyAttributes = $bodyAttributes ?? '';
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$showBackButton = !in_array($currentPage, ['dashboard.php', 'rider-dashboard.php'], true);
$backFallback = is_admin() ? 'dashboard.php' : (is_rider() ? 'rider-dashboard.php' : 'customer-dashboard.php');
$shipmentModuleReady = (bool) $pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn();
$isCustomer = ($_SESSION['role'] ?? '') === 'customer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Delivery Management System</title>
    <link rel="stylesheet" href="<?= $assetBase ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= $assetBase ?>assets/css/shipment.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body <?= $bodyAttributes ?>>
<div class="app-shell <?= is_admin() ? 'admin-app-shell' : ($isCustomer ? 'customer-app-shell' : 'rider-app-shell') ?>">
<aside class="sidebar courier-sidebar">

    <div class="brand courier-brand">
        <h2>
            <i class="bi bi-box-seam-fill"></i>
            <span>CourierFlow</span>
        </h2>
        <p>Native PHP Delivery Suite</p>
    </div>

    <nav class="nav-links courier-nav">

        <?php if (is_admin()): ?>

            <a href="<?= $assetBase ?>dashboard.php"
               class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-house-door-fill"></i>
                <span>Dashboard</span>
            </a>

            <a href="<?= $assetBase ?>admin/riders.php"
               class="nav-link <?= $currentPage === 'riders.php' ? 'active' : '' ?>">
                <i class="bi bi-bicycle"></i>
                <span>Riders</span>
            </a>

            <a href="<?= $assetBase ?>admin/parcels.php"
               class="nav-link <?= $currentPage === 'parcels.php' ? 'active' : '' ?>">
                <i class="bi bi-box-seam-fill"></i>
                <span>Bangalore City Parcels</span>
            </a>

            <?php if ($shipmentModuleReady): ?>
            <a href="<?= $assetBase ?>parcels-management.php"
               class="nav-link <?= $currentPage === 'parcels-management.php' ? 'active' : '' ?>">
                <i class="bi bi-box-seam"></i>
                <span>Parcels Management (Domestic)</span>
            </a>
            <a href="<?= $assetBase ?>parcel-list.php"
               class="nav-link <?= $currentPage === 'parcel-list.php' ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i>
                <span>Domestic Parcels</span>
            </a>
            <a href="<?= $assetBase ?>create-shipment.php"
               class="nav-link <?= $currentPage === 'create-shipment.php' ? 'active' : '' ?>">
                <i class="bi bi-plus-circle"></i>
                <span>Create Shipment (International)</span>
            </a>
            <a href="<?= $assetBase ?>shipment-list.php"
               class="nav-link <?= $currentPage === 'shipment-list.php' ? 'active' : '' ?>">
                <i class="bi bi-truck"></i>
                <span>International Shipments</span>
            </a>
            <?php endif; ?>

            <a href="<?= $assetBase ?>admin/reports.php"
               class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-fill"></i>
                <span>Reports</span>
            </a>

        <?php elseif ($isCustomer): ?>

            <a href="<?= $assetBase ?>customer-dashboard.php"
               class="nav-link <?= $currentPage === 'customer-dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-house-door-fill"></i>
                <span>Dashboard</span>
            </a>

            <?php if ($shipmentModuleReady): ?>
            <a href="<?= $assetBase ?>create-shipment.php"
               class="nav-link <?= $currentPage === 'create-shipment.php' ? 'active' : '' ?>">
                <i class="bi bi-plus-circle"></i>
                <span>Create Shipment (International)</span>
            </a>
            <a href="<?= $assetBase ?>shipment-list.php"
               class="nav-link <?= $currentPage === 'shipment-list.php' ? 'active' : '' ?>">
                <i class="bi bi-truck"></i>
                <span>My International Shipments</span>
            </a>
            <a href="<?= $assetBase ?>parcels-management.php"
               class="nav-link <?= $currentPage === 'parcels-management.php' ? 'active' : '' ?>">
                <i class="bi bi-box-seam"></i>
                <span>Parcels Management (Domestic)</span>
            </a>
            <a href="<?= $assetBase ?>parcel-list.php"
               class="nav-link <?= $currentPage === 'parcel-list.php' ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i>
                <span>My Domestic Parcels</span>
            </a>
            <?php endif; ?>

        <?php else: ?>

            <a href="<?= $assetBase ?>rider-dashboard.php"
               class="nav-link <?= $currentPage === 'rider-dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-house-door-fill"></i>
                <span>Dashboard</span>
            </a>

            <?php if ($shipmentModuleReady): ?>
            <a href="<?= $assetBase ?>parcel-list.php"
               class="nav-link <?= $currentPage === 'parcel-list.php' ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i>
                <span>Assigned Domestic Parcels</span>
            </a>
            <a href="<?= $assetBase ?>shipment-list.php"
               class="nav-link <?= $currentPage === 'shipment-list.php' ? 'active' : '' ?>">
                <i class="bi bi-truck"></i>
                <span>Assigned International Shipments</span>
            </a>
            <?php endif; ?>

            <a href="<?= $assetBase ?>profile.php"
               class="nav-link <?= $currentPage === 'profile.php' ? 'active' : '' ?>">
                <i class="bi bi-person-circle"></i>
                <span>Profile</span>
            </a>
        <?php endif; ?>

        <a href="<?= $assetBase ?>logout.php" class="nav-link">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>
    <main class="content">
        <header class="topbar">
            <div>
                <h1><?= is_admin() ? 'Admin Console' : ($isCustomer ? 'Customer Console' : 'Rider Console') ?></h1>
                <p>Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <div class="topbar__actions">
                <?php if ($showBackButton): ?>
                    <button class="btn btn-secondary back-button" type="button" data-back-button data-back-fallback="<?= $assetBase . $backFallback ?>">
                        <i class="bi bi-arrow-left"></i>
                        <span>Back</span>
                    </button>
                <?php endif; ?>
                <span class="status-pill"><?= is_admin() ? 'Admin' : ($isCustomer ? 'Customer' : 'Delivery Rider') ?></span>
            </div>
        </header>
