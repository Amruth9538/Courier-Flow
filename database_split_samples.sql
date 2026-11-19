USE parcel_delivery_system;

-- Import database_shipments.sql and database_split.sql first.
-- Sample bookings are attached to the first admin account and tagged with
-- stable references so rerunning this script does not add duplicate samples.
SET @demo_user_id := (
    SELECT id FROM users
    WHERE role = 'admin'
    ORDER BY id
    LIMIT 1
);
SELECT IF(
    @demo_user_id IS NULL,
    'ERROR: Create an admin account before importing demo bookings.',
    CONCAT('Demo bookings will be assigned to admin user id ', @demo_user_id, '.')
) AS seed_preflight;
SET @domestic_tracking := CONCAT('DM', DATE_FORMAT(CURRENT_DATE(), '%Y%m%d'), '900001');
SET @international_tracking := CONCAT('IN', DATE_FORMAT(CURRENT_DATE(), '%Y%m%d'), '900001');

INSERT INTO shipments (
    tracking_no, user_id, shipment_type, status, reference_number,
    shipper_name, shipper_company, shipper_country, shipper_postal_code,
    shipper_state, shipper_state_code, shipper_city, shipper_sub_urb,
    shipper_landmark, shipper_address_line1, shipper_address_line2,
    shipper_email, shipper_country_code, shipper_mobile,
    shipper_identification_type, shipper_identification_number,
    receiver_name, receiver_company, receiver_country, receiver_postal_code,
    receiver_state, receiver_state_code, receiver_city, receiver_sub_urb,
    receiver_landmark, receiver_address_line1, receiver_address_line2,
    receiver_email, receiver_country_code, receiver_mobile,
    receiver_identification_type, receiver_identification_number,
    package_type, pieces, carrier, service, currency, contents,
    contents_summarized, customs_status, terms_of_delivery, packaging,
    actual_wt, vol_wt, chg_wt, csb_type, term_of_invoice, export_reason,
    gst_invoice, ship_method, total_value, payment_mode, cod_amount,
    declared_value, delivery_instructions, service_type, gstin
)
SELECT
    @domestic_tracking, @demo_user_id, 'domestic', 'Booked', 'DEMO-DOMESTIC-COURIERFLOW',
    'Rahul Sharma', 'Demo Trading 1', 'INDIA', '560001',
    'Karnataka', 'IN', 'Bengaluru', 'Bengaluru',
    'Near Demo Park', '10 Sample Road', 'Demo Layout',
    'sender.demo@example.test', '+91', '9876543210',
    'Domestic', 'N/A',
    'Rohan Gupta', '', 'INDIA', '110001',
    'Delhi', 'IN', 'New Delhi', 'New Delhi',
    'Near Sample Plaza', '20 Example Street', 'Central Block',
    'receiver.demo@example.test', '+91', '9123456789',
    'Domestic', 'N/A',
    'Non Docs', 1, 'Self', 'Surface', 'INR', 'Books and stationery',
    1, 'Domestic', 'Domestic', 'Customer packaging',
    1.250, 0.240, 1.250, 'Domestic', 'Domestic', 'Domestic',
    0, 'Drop Off at Carrier', 1200.00, 'Prepaid', NULL,
    1200.00, 'Leave at reception', 'Surface', NULL
FROM users
WHERE id = @demo_user_id
  AND NOT EXISTS (
      SELECT 1 FROM shipments
      WHERE user_id = @demo_user_id
        AND shipment_type = 'domestic'
        AND reference_number = 'DEMO-DOMESTIC-COURIERFLOW'
  );

INSERT INTO shipments (
    tracking_no, user_id, shipment_type, status, reference_number,
    shipper_name, shipper_company, shipper_country, shipper_postal_code,
    shipper_state, shipper_state_code, shipper_city, shipper_sub_urb,
    shipper_landmark, shipper_address_line1, shipper_address_line2,
    shipper_email, shipper_country_code, shipper_mobile,
    shipper_identification_type, shipper_identification_number,
    receiver_name, receiver_company, receiver_country, receiver_postal_code,
    receiver_state, receiver_state_code, receiver_city, receiver_sub_urb,
    receiver_landmark, receiver_address_line1, receiver_address_line2,
    receiver_email, receiver_country_code, receiver_mobile,
    receiver_identification_type, receiver_identification_number,
    package_type, pieces, carrier, service, currency, contents,
    contents_summarized, customs_status, terms_of_delivery, packaging,
    actual_wt, vol_wt, chg_wt, csb_type, term_of_invoice, export_reason,
    gst_invoice, ship_method, total_value
)
SELECT
    @international_tracking, @demo_user_id, 'international', 'Booked', 'DEMO-INTERNATIONAL-COURIERFLOW',
    'Rahul Sharma', 'Demo Trading 1', 'INDIA', '560001',
    'Karnataka', '29', 'Bengaluru', 'Bengaluru',
    NULL, '10 Sample Road', 'Demo Layout',
    'sender.demo@example.test', '+91', '9876543210',
    'Passport', 'DEMO1234',
    'Emily Johnson', 'Example Imports 2', 'UNITED STATES', '10001',
    'New York', 'NY', 'New York', 'New York',
    NULL, '500 Example Avenue', 'Suite 10',
    'receiver.demo@example.test', '+1', '2125550100',
    'Driving License', 'DEMO12345678',
    'Non Docs', 1, 'CourierFlow', 'Express Saver', 'USD', 'Cotton shirts',
    1, 'UnPaid', 'Commercial shipment', 'Use My Own Package',
    0.850, 0.640, 0.850, 'CSB 4', 'DDU', 'Commercial',
    0, 'Drop Off at Carrier', 45.00
FROM users
WHERE id = @demo_user_id
  AND NOT EXISTS (
      SELECT 1 FROM shipments
      WHERE user_id = @demo_user_id
        AND shipment_type = 'international'
        AND reference_number = 'DEMO-INTERNATIONAL-COURIERFLOW'
  );

INSERT INTO shipment_packages (shipment_id, length, width, height, unit, actual_wt, vol_wt)
SELECT s.id, 30.00, 20.00, 20.00, 'CM', s.actual_wt, s.vol_wt
FROM shipments s
WHERE s.reference_number IN ('DEMO-DOMESTIC-COURIERFLOW', 'DEMO-INTERNATIONAL-COURIERFLOW')
  AND s.user_id = @demo_user_id
  AND NOT EXISTS (
      SELECT 1 FROM shipment_packages p WHERE p.shipment_id = s.id
  );

INSERT INTO shipment_commodities (shipment_id, description, hs_code, qty, unit_price, total, unit, country_of_origin)
SELECT s.id, 'Cotton shirts', '610910', 2, 22.50, 45.00, 'PCS', 'INDIA'
FROM shipments s
WHERE s.user_id = @demo_user_id
  AND s.shipment_type = 'international'
  AND s.reference_number = 'DEMO-INTERNATIONAL-COURIERFLOW'
  AND NOT EXISTS (
      SELECT 1 FROM shipment_commodities c WHERE c.shipment_id = s.id
  );

INSERT INTO shipment_status_history (shipment_id, status, note)
SELECT s.id, s.status, 'Idempotent demo booking.'
FROM shipments s
WHERE s.user_id = @demo_user_id
  AND s.reference_number IN ('DEMO-DOMESTIC-COURIERFLOW', 'DEMO-INTERNATIONAL-COURIERFLOW')
  AND NOT EXISTS (
      SELECT 1 FROM shipment_status_history h WHERE h.shipment_id = s.id
  );
