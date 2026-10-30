<?php
// 获取当前文件名，用于高亮当前选中的菜单
$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
/* CourierFlow 左侧导航栏样式 */
.main-sidebar {
    width: 240px;
    background-color: #0d1527; /* 深色背景 */
    color: #ffffff;
    min-height: 100vh;
    padding: 24px 16px;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
}

.sidebar-brand {
    margin-bottom: 24px;
    padding-left: 8px;
}

.sidebar-brand h1 {
    font-size: 22px;
    font-weight: 700;
    margin: 0;
    color: #ffffff;
    letter-spacing: 0.5px;
}

.sidebar-brand p {
    font-size: 12px;
    color: #64748b;
    margin: 4px 0 0 0;
}

.sidebar-nav {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    padding: 10px 14px;
    color: #94a3b8;
    text-decoration: none;
    font-size: 15px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.sidebar-nav a:hover {
    background-color: #1e293b;
    color: #ffffff;
}

.sidebar-nav a.active {
    background-color: #2563eb;
    color: #ffffff;
    font-weight: 600;
}

.sidebar-divider {
    height: 1px;
    background-color: #1e293b;
    margin: 16px 0;
}

.sidebar-nav a.logout {
    color: #f87171;
}

.sidebar-nav a.logout:hover {
    background-color: rgba(239, 68, 68, 0.12);
    color: #ef4444;
}
</style>

<aside class="main-sidebar">
    <div class="sidebar-brand">
        <h1>CourierFlow</h1>
        <p>Native PHP Delivery Suite</p>
    </div>

    <ul class="sidebar-nav">
        <li>
            <a href="parcels.php" class="<?= $current_page === 'parcels.php' ? 'active' : '' ?>">
                Parcels
            </a>
        </li>
        <li>
            <a href="riders.php" class="<?= $current_page === 'riders.php' ? 'active' : '' ?>">
                Riders
            </a>
        </li>
        <li>
            <a href="reports.php" class="<?= $current_page === 'reports.php' ? 'active' : '' ?>">
                Reports
            </a>
        </li>
        <li>
            <a href="profile.php" class="<?= $current_page === 'profile.php' ? 'active' : '' ?>">
                Profile
            </a>
        </li>

        <div class="sidebar-divider"></div>

        <li>
            <a href="../logout.php" class="logout">
                Logout
            </a>
        </li>
    </ul>
</aside>