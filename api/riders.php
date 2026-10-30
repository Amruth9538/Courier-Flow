<?php
require_once __DIR__ . '/../config.php';
require_auth();

$stmt = $pdo->query("
    SELECT
        r.id,
        r.is_online,
        r.current_latitude,
        r.current_longitude,
        r.last_seen,
        (
    SELECT p.id
    FROM parcels p
    WHERE p.assigned_rider_id = r.id
      AND p.status NOT IN ('Delivered', 'Cancelled')
    ORDER BY
        CASE
            WHEN p.status IN ('In Transit', 'Out for Delivery') THEN 0
            ELSE 1
        END,
        p.created_at DESC
    LIMIT 1
) AS active_parcel_id,
        u.full_name,
        CASE WHEN r.is_online = 1 THEN 'Online' ELSE 'Offline' END AS status
    FROM riders r
    JOIN users u ON u.id = r.user_id
    WHERE r.is_online = 1 AND u.role = 'rider'
    ORDER BY u.full_name ASC
");
$rows = $stmt->fetchAll();
header('Content-Type: application/json');
echo json_encode($rows);
