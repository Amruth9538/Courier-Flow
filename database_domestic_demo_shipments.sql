USE parcel_delivery_system;

-- Adds ten repeatable domestic demo shipments to the first admin account.
-- Existing shipments are preserved; stable reference numbers prevent reruns
-- from adding duplicates.
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
    declared_value, delivery_instructions, service_type
)
SELECT
    CONCAT('DM', DATE_FORMAT(CURRENT_DATE(), '%Y%m%d'), LPAD(910000 + d.n, 6, '0')),
    u.id,
    'domestic',
    d.status,
    CONCAT('DEMO-DOMESTIC-2026-', LPAD(d.n, 2, '0')),
    d.sender,
    '',
    'INDIA',
    '560001',
    'Karnataka',
    'KA',
    'Bengaluru',
    'Bengaluru',
    'Near Demo Park',
    '10 Demo Road',
    'Demo Layout',
    'sender.demo@example.test',
    '+91',
    '9000000000',
    'Domestic',
    'N/A',
    d.receiver,
    '',
    'INDIA',
    d.postal,
    d.state,
    d.state_code,
    d.city,
    d.city,
    NULL,
    CONCAT('Demo Address ', d.n),
    'Residential Area',
    CONCAT('receiver', d.n, '@example.test'),
    '+91',
    CONCAT('90000000', LPAD(d.n, 2, '0')),
    'Domestic',
    'N/A',
    'Non Docs',
    1,
    'Self',
    d.service,
    'INR',
    d.contents,
    1,
    'Domestic',
    'Domestic',
    'Customer packaging',
    d.weight,
    1.200,
    GREATEST(d.weight, 1.200),
    'Domestic',
    'Domestic',
    'Domestic',
    0,
    'Drop Off at Carrier',
    d.declared_value,
    d.payment_mode,
    d.cod_amount,
    d.declared_value,
    'Please deliver during business hours',
    d.service
FROM (
    SELECT id
    FROM users
    WHERE role = 'admin'
    ORDER BY id
    LIMIT 1
) AS u
CROSS JOIN (
    SELECT 1 AS n, 'Rahul Sharma' AS sender, 'Aarav Sharma' AS receiver, 'Mumbai' AS city, 'Maharashtra' AS state, 'MH' AS state_code, '400001' AS postal, 'Cotton garments' AS contents, 1.200 AS weight, 2500.00 AS declared_value, 'Prepaid' AS payment_mode, NULL AS cod_amount, 'Surface' AS service, 'Booked' AS status
    UNION ALL SELECT 2, 'Priya Patel', 'Diya Patel', 'Delhi', 'Delhi', 'DL', '110001', 'Books and stationery', 2.000, 1800.00, 'COD', 1800.00, 'Air', 'Booked'
    UNION ALL SELECT 3, 'Amit Singh', 'Kabir Singh', 'Jaipur', 'Rajasthan', 'RJ', '302001', 'Handcrafted decor', 3.500, 4200.00, 'To-Pay', NULL, 'Surface', 'Picked Up'
    UNION ALL SELECT 4, 'Neha Nair', 'Meera Nair', 'Chennai', 'Tamil Nadu', 'TN', '600001', 'Kitchen utensils', 4.000, 3200.00, 'Prepaid', NULL, 'Express', 'In Transit'
    UNION ALL SELECT 5, 'Vijay Menon', 'Ishaan Menon', 'Kochi', 'Kerala', 'KL', '682001', 'Sports accessories', 1.750, 2100.00, 'COD', 2100.00, 'Air', 'Out for Delivery'
    UNION ALL SELECT 6, 'Kavya Deshmukh', 'Anaya Deshmukh', 'Pune', 'Maharashtra', 'MH', '411001', 'Office supplies', 5.500, 5600.00, 'Prepaid', NULL, 'Surface', 'Delivered'
    UNION ALL SELECT 7, 'Ravi Verma', 'Vivaan Verma', 'Lucknow', 'Uttar Pradesh', 'UP', '226001', 'Home textiles', 2.800, 3900.00, 'To-Pay', NULL, 'Express', 'Booked'
    UNION ALL SELECT 8, 'Pooja Shah', 'Sara Shah', 'Ahmedabad', 'Gujarat', 'GJ', '380001', 'Personal care products', 1.500, 1400.00, 'COD', 1400.00, 'Air', 'Picked Up'
    UNION ALL SELECT 9, 'Karan Joshi', 'Arjun Joshi', 'Bhopal', 'Madhya Pradesh', 'MP', '462001', 'Electronic accessories', 6.200, 8900.00, 'Prepaid', NULL, 'Surface', 'In Transit'
    UNION ALL SELECT 10, 'Anjali Reddy', 'Tara Reddy', 'Visakhapatnam', 'Andhra Pradesh', 'AP', '530001', 'Craft materials', 1.250, 1600.00, 'Prepaid', NULL, 'Express', 'Booked'
) AS d
WHERE NOT EXISTS (
    SELECT 1
    FROM shipments s
    WHERE s.reference_number = CONCAT('DEMO-DOMESTIC-2026-', LPAD(d.n, 2, '0'))
);

INSERT INTO shipment_packages (
    shipment_id, length, width, height, unit, actual_wt, vol_wt
)
SELECT s.id, 30.00, 20.00, 10.00, 'CM', s.actual_wt, s.vol_wt
FROM shipments s
WHERE s.reference_number BETWEEN 'DEMO-DOMESTIC-2026-01' AND 'DEMO-DOMESTIC-2026-10'
  AND NOT EXISTS (
      SELECT 1
      FROM shipment_packages p
      WHERE p.shipment_id = s.id
  );

INSERT INTO shipment_status_history (shipment_id, status, note)
SELECT s.id, s.status, 'Domestic demo shipment.'
FROM shipments s
WHERE s.reference_number BETWEEN 'DEMO-DOMESTIC-2026-01' AND 'DEMO-DOMESTIC-2026-10'
  AND NOT EXISTS (
      SELECT 1
      FROM shipment_status_history h
      WHERE h.shipment_id = s.id
  );
