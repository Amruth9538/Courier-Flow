USE parcel_delivery_system;

START TRANSACTION;

UPDATE parcels
SET assigned_rider_id = CASE tracking_number
    WHEN 'DEMOLOCAL001' THEN (
        SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id
        WHERE u.full_name = 'Kiran' ORDER BY r.id LIMIT 1
    )
    WHEN 'DEMOLOCAL002' THEN (
        SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id
        WHERE u.full_name = 'Test Rider' ORDER BY r.id LIMIT 1
    )
    WHEN 'DEMOLOCAL003' THEN (
        SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id
        WHERE u.full_name = 'arun' ORDER BY r.id LIMIT 1
    )
    WHEN 'DEMOLOCAL004' THEN (
        SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id
        WHERE u.full_name = 'Kiran' ORDER BY r.id LIMIT 1
    )
    WHEN 'DEMOLOCAL005' THEN (
        SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id
        WHERE u.full_name = 'Test Rider' ORDER BY r.id LIMIT 1
    )
    WHEN 'DEMOLOCAL006' THEN (
        SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id
        WHERE u.full_name = 'arun' ORDER BY r.id LIMIT 1
    )
    WHEN 'DEMOLOCAL007' THEN (
        SELECT r.id FROM riders r JOIN users u ON u.id = r.user_id
        WHERE u.full_name = 'Kiran' ORDER BY r.id LIMIT 1
    )
END
WHERE tracking_number IN (
    'DEMOLOCAL001', 'DEMOLOCAL002', 'DEMOLOCAL003', 'DEMOLOCAL004',
    'DEMOLOCAL005', 'DEMOLOCAL006', 'DEMOLOCAL007'
);

UPDATE delivery_route_snapshots route
JOIN parcels p ON p.id = route.parcel_id
SET route.rider_id = p.assigned_rider_id
WHERE p.tracking_number IN (
    'DEMOLOCAL001', 'DEMOLOCAL002', 'DEMOLOCAL003', 'DEMOLOCAL004',
    'DEMOLOCAL005', 'DEMOLOCAL006', 'DEMOLOCAL007'
)
AND p.assigned_rider_id IS NOT NULL;

COMMIT;

SELECT p.tracking_number, u.full_name AS assigned_rider
FROM parcels p
JOIN riders r ON r.id = p.assigned_rider_id
JOIN users u ON u.id = r.user_id
WHERE p.tracking_number IN (
    'DEMOLOCAL001', 'DEMOLOCAL002', 'DEMOLOCAL003', 'DEMOLOCAL004',
    'DEMOLOCAL005', 'DEMOLOCAL006', 'DEMOLOCAL007'
)
ORDER BY p.tracking_number;
