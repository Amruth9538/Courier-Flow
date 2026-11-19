USE parcel_delivery_system;

-- Adds seven repeatable Bengaluru demo parcels for the 15-minute dashboard.
-- These are unassigned demos; route snapshots are synthetic and do not update
-- rider availability or overwrite existing parcels.
INSERT INTO parcels (
    tracking_number, recipient_name, recipient_phone, recipient_address,
    recipient_latitude, recipient_longitude, pickup_address, parcel_type,
    weight_kg, status, assigned_rider_id, created_by, created_at
)
SELECT
    CONCAT('DEMOLOCAL', LPAD(d.n, 3, '0')),
    d.recipient_name,
    CONCAT('90000000', LPAD(d.n, 2, '0')),
    d.delivery_address,
    d.end_latitude,
    d.end_longitude,
    'CourierFlow Demo Hub, Bengaluru, Karnataka',
    d.parcel_type,
    d.weight_kg,
    'Pending',
    NULL,
    admin_user.id,
    NOW()
FROM (
    SELECT 1 AS n, 'Aarav Sharma' AS recipient_name, '12 Demo Street, Indiranagar, Bengaluru, Karnataka 560038' AS delivery_address, '12.97840000' AS end_latitude, '77.64080000' AS end_longitude, 'Documents' AS parcel_type, 0.30 AS weight_kg
    UNION ALL SELECT 2, 'Diya Patel', '24 Sample Road, Koramangala, Bengaluru, Karnataka 560034', '12.93520000', '77.62450000', 'Small Parcel', 1.20
    UNION ALL SELECT 3, 'Kabir Singh', '8 Example Avenue, Jayanagar, Bengaluru, Karnataka 560041', '12.92500000', '77.58380000', 'Electronics', 2.40
    UNION ALL SELECT 4, 'Meera Nair', '15 Demo Lane, Malleshwaram, Bengaluru, Karnataka 560003', '13.00350000', '77.57000000', 'Books', 1.00
    UNION ALL SELECT 5, 'Ishaan Menon', '6 Practice Road, Basavanagudi, Bengaluru, Karnataka 560004', '12.94300000', '77.56800000', 'Clothing', 1.80
    UNION ALL SELECT 6, 'Anaya Deshmukh', '31 Sample Layout, HSR Layout, Bengaluru, Karnataka 560102', '12.91160000', '77.64730000', 'Small Parcel', 0.75
    UNION ALL SELECT 7, 'Vivaan Verma', '9 Test Cross, Rajajinagar, Bengaluru, Karnataka 560010', '12.99150000', '77.55600000', 'Documents', 0.20
) AS d
CROSS JOIN (
    SELECT id
    FROM users
    WHERE role = 'admin'
    ORDER BY id
    LIMIT 1
) AS admin_user
WHERE NOT EXISTS (
    SELECT 1
    FROM parcels existing
    WHERE existing.tracking_number = CONCAT('DEMOLOCAL', LPAD(d.n, 3, '0'))
);

INSERT INTO delivery_route_snapshots (
    parcel_id, rider_id, start_latitude, start_longitude,
    end_latitude, end_longitude, distance_km, duration_min, route_geometry
)
SELECT
    p.id,
    demo_rider.id,
    12.97160000,
    77.59460000,
    p.recipient_latitude,
    p.recipient_longitude,
    d.distance_km,
    d.duration_min,
    NULL
FROM (
    SELECT 1 AS n, 2.2 AS distance_km, 5.0 AS duration_min
    UNION ALL SELECT 2, 3.1, 7.0
    UNION ALL SELECT 3, 3.7, 9.0
    UNION ALL SELECT 4, 2.8, 8.0
    UNION ALL SELECT 5, 3.0, 10.0
    UNION ALL SELECT 6, 4.2, 12.0
    UNION ALL SELECT 7, 3.5, 11.0
) AS d
JOIN parcels p
    ON p.tracking_number = CONCAT('DEMOLOCAL', LPAD(d.n, 3, '0'))
CROSS JOIN (
    SELECT id
    FROM riders
    ORDER BY id
    LIMIT 1
) AS demo_rider
WHERE NOT EXISTS (
    SELECT 1
    FROM delivery_route_snapshots existing
    WHERE existing.parcel_id = p.id
);
