USE parcel_delivery_system;

-- Keep the completed demo as history, but return the remaining demo parcels
-- to the unassigned queue. Existing non-demo assignments are untouched.
START TRANSACTION;

UPDATE parcels
SET assigned_rider_id = NULL
WHERE tracking_number IN (
    'DEMOLOCAL002', 'DEMOLOCAL003', 'DEMOLOCAL004',
    'DEMOLOCAL005', 'DEMOLOCAL006', 'DEMOLOCAL007'
)
AND status NOT IN ('Delivered', 'Cancelled');

UPDATE delivery_route_snapshots route
JOIN parcels p ON p.id = route.parcel_id
SET route.distance_km = CASE p.tracking_number
        WHEN 'DEMOLOCAL001' THEN 2.2
        WHEN 'DEMOLOCAL002' THEN 3.1
        WHEN 'DEMOLOCAL003' THEN 3.7
        WHEN 'DEMOLOCAL004' THEN 2.8
        WHEN 'DEMOLOCAL005' THEN 3.0
        WHEN 'DEMOLOCAL006' THEN 4.2
        WHEN 'DEMOLOCAL007' THEN 3.5
    END,
    route.duration_min = CASE p.tracking_number
        WHEN 'DEMOLOCAL001' THEN 5.0
        WHEN 'DEMOLOCAL002' THEN 7.0
        WHEN 'DEMOLOCAL003' THEN 9.0
        WHEN 'DEMOLOCAL004' THEN 8.0
        WHEN 'DEMOLOCAL005' THEN 10.0
        WHEN 'DEMOLOCAL006' THEN 12.0
        WHEN 'DEMOLOCAL007' THEN 11.0
    END
WHERE p.tracking_number IN (
    'DEMOLOCAL001', 'DEMOLOCAL002', 'DEMOLOCAL003', 'DEMOLOCAL004',
    'DEMOLOCAL005', 'DEMOLOCAL006', 'DEMOLOCAL007'
);

COMMIT;

SELECT p.tracking_number, p.status, u.full_name AS assigned_rider, route.duration_min AS demo_eta_minutes
FROM parcels p
LEFT JOIN riders r ON r.id = p.assigned_rider_id
LEFT JOIN users u ON u.id = r.user_id
LEFT JOIN delivery_route_snapshots route ON route.parcel_id = p.id
WHERE p.tracking_number LIKE 'DEMOLOCAL%'
ORDER BY p.tracking_number;
