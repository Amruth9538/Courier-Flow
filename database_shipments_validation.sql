USE parcel_delivery_system;

-- Add booking fields needed by strict shipment validation without replacing data.
ALTER TABLE shipments
    ADD COLUMN IF NOT EXISTS gst_legal_name VARCHAR(80) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS gst_amount DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS gst_igst DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pickup_slot VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pickup_contact_name VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pickup_contact_phone VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pickup_instructions VARCHAR(200) DEFAULT NULL;

ALTER TABLE shipment_commodities
    ADD COLUMN IF NOT EXISTS unit ENUM('PCS', 'KG', 'BOX', 'SET') NOT NULL DEFAULT 'PCS',
    ADD COLUMN IF NOT EXISTS country_of_origin VARCHAR(100) NOT NULL DEFAULT 'INDIA';
