USE parcel_delivery_system;

-- Existing shipments predate the domestic booking module, so classify them as
-- international when this column is introduced.
ALTER TABLE shipments
    ADD COLUMN IF NOT EXISTS shipment_type
        ENUM('domestic', 'international') NOT NULL DEFAULT 'international';

UPDATE shipments
SET shipment_type = 'international'
WHERE shipment_type IS NULL OR shipment_type = '';

-- Domestic address snapshots use the existing shipper_* and receiver_* columns.
-- Keep separate landmarks so both address cards retain their own value.
ALTER TABLE shipments
    ADD COLUMN IF NOT EXISTS shipper_landmark VARCHAR(120) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS receiver_landmark VARCHAR(120) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assigned_rider_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_mode ENUM('Prepaid', 'COD', 'To-Pay') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS cod_amount DECIMAL(12,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS declared_value DECIMAL(12,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS delivery_instructions VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS service_type ENUM('Surface', 'Air', 'Express') DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_shipments_assigned_rider
    ON shipments (assigned_rider_id, shipment_type, created_at);

-- Customs clearance is part of the international shipment lifecycle.
ALTER TABLE shipments
    MODIFY COLUMN status
        ENUM('Booked', 'Picked Up', 'Customs Clearance', 'In Transit',
             'Out for Delivery', 'Delivered', 'Cancelled')
        NOT NULL DEFAULT 'Booked';

ALTER TABLE shipment_status_history
    MODIFY COLUMN status
        ENUM('Booked', 'Picked Up', 'Customs Clearance', 'In Transit',
             'Out for Delivery', 'Delivered', 'Cancelled')
        NOT NULL;

-- The duplicate-index error can be ignored if this index already exists on a
-- MariaDB/MySQL version that supports CREATE INDEX IF NOT EXISTS.
CREATE INDEX IF NOT EXISTS idx_shipments_type_status_created
    ON shipments (shipment_type, status, created_at);

CREATE TABLE IF NOT EXISTS domestic_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service ENUM('Surface', 'Air', 'Express') NOT NULL,
    min_wt DECIMAL(8,3) NOT NULL,
    max_wt DECIMAL(8,3) NOT NULL,
    rate DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_domestic_rates_service_slab (service, min_wt, max_wt),
    CONSTRAINT chk_domestic_rates_weight_range CHECK (min_wt >= 0 AND max_wt > min_wt),
    CONSTRAINT chk_domestic_rates_amount CHECK (rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rates are quoted per weight slab (not per kg). Insert defaults only when the
-- corresponding slab is absent so rerunning the migration keeps admin edits.
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Surface', 0.000, 0.500, 40.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Surface' AND min_wt = 0.000 AND max_wt = 0.500
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Surface', 0.500, 1.000, 60.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Surface' AND min_wt = 0.500 AND max_wt = 1.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Surface', 1.000, 2.000, 90.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Surface' AND min_wt = 1.000 AND max_wt = 2.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Surface', 2.000, 5.000, 150.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Surface' AND min_wt = 2.000 AND max_wt = 5.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Surface', 5.000, 10.000, 260.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Surface' AND min_wt = 5.000 AND max_wt = 10.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Surface', 10.000, 999.999, 500.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Surface' AND min_wt = 10.000 AND max_wt = 999.999
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Air', 0.000, 0.500, 70.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Air' AND min_wt = 0.000 AND max_wt = 0.500
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Air', 0.500, 1.000, 110.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Air' AND min_wt = 0.500 AND max_wt = 1.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Air', 1.000, 2.000, 170.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Air' AND min_wt = 1.000 AND max_wt = 2.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Air', 2.000, 5.000, 300.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Air' AND min_wt = 2.000 AND max_wt = 5.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Air', 5.000, 10.000, 520.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Air' AND min_wt = 5.000 AND max_wt = 10.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Air', 10.000, 999.999, 900.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Air' AND min_wt = 10.000 AND max_wt = 999.999
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Express', 0.000, 0.500, 100.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Express' AND min_wt = 0.000 AND max_wt = 0.500
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Express', 0.500, 1.000, 160.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Express' AND min_wt = 0.500 AND max_wt = 1.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Express', 1.000, 2.000, 250.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Express' AND min_wt = 1.000 AND max_wt = 2.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Express', 2.000, 5.000, 450.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Express' AND min_wt = 2.000 AND max_wt = 5.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Express', 5.000, 10.000, 800.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Express' AND min_wt = 5.000 AND max_wt = 10.000
);
INSERT INTO domestic_rates (service, min_wt, max_wt, rate)
SELECT 'Express', 10.000, 999.999, 1400.00
WHERE NOT EXISTS (
    SELECT 1 FROM domestic_rates
    WHERE service = 'Express' AND min_wt = 10.000 AND max_wt = 999.999
);
