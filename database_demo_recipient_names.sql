USE parcel_delivery_system;

-- Update only the generated demo shipments. Normal customer shipments are
-- left unchanged.
UPDATE shipments
SET receiver_name = 'Rohan Gupta'
WHERE shipment_type = 'domestic'
  AND reference_number = 'DEMO-DOMESTIC-COURIERFLOW';

UPDATE shipments
SET receiver_name = CASE receiver_name
    WHEN 'Sample Recipient 01' THEN 'Olivia Bennett'
    WHEN 'Sample Recipient 02' THEN 'Ethan Carter'
    WHEN 'Sample Recipient 03' THEN 'Sophia Mitchell'
    WHEN 'Sample Recipient 04' THEN 'Noah Anderson'
    WHEN 'Sample Recipient 05' THEN 'Ava Thompson'
    WHEN 'Sample Recipient 06' THEN 'Liam Parker'
    WHEN 'Sample Recipient 07' THEN 'Isabella Brooks'
    WHEN 'Sample Recipient 08' THEN 'Mason Reed'
    WHEN 'Sample Recipient 09' THEN 'Mia Collins'
    WHEN 'Sample Recipient 10' THEN 'James Foster'
END
WHERE shipment_type = 'international'
  AND receiver_name IN (
      'Sample Recipient 01', 'Sample Recipient 02',
      'Sample Recipient 03', 'Sample Recipient 04',
      'Sample Recipient 05', 'Sample Recipient 06',
      'Sample Recipient 07', 'Sample Recipient 08',
      'Sample Recipient 09', 'Sample Recipient 10'
  );

UPDATE shipments
SET receiver_name = CASE reference_number
    WHEN 'DEMO-DOMESTIC-2026-01' THEN 'Aarav Sharma'
    WHEN 'DEMO-DOMESTIC-2026-02' THEN 'Diya Patel'
    WHEN 'DEMO-DOMESTIC-2026-03' THEN 'Kabir Singh'
    WHEN 'DEMO-DOMESTIC-2026-04' THEN 'Meera Nair'
    WHEN 'DEMO-DOMESTIC-2026-05' THEN 'Ishaan Menon'
    WHEN 'DEMO-DOMESTIC-2026-06' THEN 'Anaya Deshmukh'
    WHEN 'DEMO-DOMESTIC-2026-07' THEN 'Vivaan Verma'
    WHEN 'DEMO-DOMESTIC-2026-08' THEN 'Sara Shah'
    WHEN 'DEMO-DOMESTIC-2026-09' THEN 'Arjun Joshi'
    WHEN 'DEMO-DOMESTIC-2026-10' THEN 'Tara Reddy'
    WHEN 'DEMO-DOMESTIC-COURIERFLOW' THEN 'Rohan Gupta'
END
WHERE shipment_type = 'domestic'
  AND (
      reference_number BETWEEN 'DEMO-DOMESTIC-2026-01' AND 'DEMO-DOMESTIC-2026-10'
      OR reference_number = 'DEMO-DOMESTIC-COURIERFLOW'
  );

UPDATE shipments
SET receiver_name = CASE
    WHEN reference_number = 'DEMO-INTERNATIONAL-COURIERFLOW' THEN 'Emily Johnson'
    WHEN receiver_name = 'Sample Recipient 01' THEN 'Olivia Bennett'
    WHEN receiver_name = 'Sample Recipient 02' THEN 'Ethan Carter'
    WHEN receiver_name = 'Sample Recipient 03' THEN 'Sophia Mitchell'
    WHEN receiver_name = 'Sample Recipient 04' THEN 'Noah Anderson'
    WHEN receiver_name = 'Sample Recipient 05' THEN 'Ava Thompson'
    WHEN receiver_name = 'Sample Recipient 06' THEN 'Liam Parker'
    WHEN receiver_name = 'Sample Recipient 07' THEN 'Isabella Brooks'
    WHEN receiver_name = 'Sample Recipient 08' THEN 'Mason Reed'
    WHEN receiver_name = 'Sample Recipient 09' THEN 'Mia Collins'
    WHEN receiver_name = 'Sample Recipient 10' THEN 'James Foster'
END
WHERE shipment_type = 'international'
  AND (
      reference_number = 'DEMO-INTERNATIONAL-COURIERFLOW'
      OR receiver_name IN (
          'Sample Recipient 01', 'Sample Recipient 02',
          'Sample Recipient 03', 'Sample Recipient 04',
          'Sample Recipient 05', 'Sample Recipient 06',
          'Sample Recipient 07', 'Sample Recipient 08',
          'Sample Recipient 09', 'Sample Recipient 10'
      )
  );

UPDATE shipments
SET shipper_name = CASE reference_number
    WHEN 'DEMO-DOMESTIC-2026-01' THEN 'Rahul Sharma'
    WHEN 'DEMO-DOMESTIC-2026-02' THEN 'Priya Patel'
    WHEN 'DEMO-DOMESTIC-2026-03' THEN 'Amit Singh'
    WHEN 'DEMO-DOMESTIC-2026-04' THEN 'Neha Nair'
    WHEN 'DEMO-DOMESTIC-2026-05' THEN 'Vijay Menon'
    WHEN 'DEMO-DOMESTIC-2026-06' THEN 'Kavya Deshmukh'
    WHEN 'DEMO-DOMESTIC-2026-07' THEN 'Ravi Verma'
    WHEN 'DEMO-DOMESTIC-2026-08' THEN 'Pooja Shah'
    WHEN 'DEMO-DOMESTIC-2026-09' THEN 'Karan Joshi'
    WHEN 'DEMO-DOMESTIC-2026-10' THEN 'Anjali Reddy'
    WHEN 'DEMO-DOMESTIC-COURIERFLOW' THEN 'Rahul Sharma'
END
WHERE shipment_type = 'domestic'
  AND (
      reference_number BETWEEN 'DEMO-DOMESTIC-2026-01' AND 'DEMO-DOMESTIC-2026-10'
      OR reference_number = 'DEMO-DOMESTIC-COURIERFLOW'
  );

UPDATE shipments
SET shipper_name = CASE
    WHEN reference_number = 'DEMO-INTERNATIONAL-COURIERFLOW' THEN 'Rahul Sharma'
    WHEN receiver_name = 'Olivia Bennett' THEN 'Daniel Miller'
    WHEN receiver_name = 'Ethan Carter' THEN 'Grace Wilson'
    WHEN receiver_name = 'Sophia Mitchell' THEN 'Jack Taylor'
    WHEN receiver_name = 'Noah Anderson' THEN 'Lily Moore'
    WHEN receiver_name = 'Ava Thompson' THEN 'Benjamin Davis'
    WHEN receiver_name = 'Liam Parker' THEN 'Chloe Martin'
    WHEN receiver_name = 'Isabella Brooks' THEN 'Henry Lewis'
    WHEN receiver_name = 'Mason Reed' THEN 'Ella Walker'
    WHEN receiver_name = 'Mia Collins' THEN 'Lucas Hall'
    WHEN receiver_name = 'James Foster' THEN 'Amelia Young'
END
WHERE shipment_type = 'international'
  AND (
      reference_number = 'DEMO-INTERNATIONAL-COURIERFLOW'
      OR receiver_name IN (
          'Olivia Bennett', 'Ethan Carter', 'Sophia Mitchell',
          'Noah Anderson', 'Ava Thompson', 'Liam Parker',
          'Isabella Brooks', 'Mason Reed', 'Mia Collins', 'James Foster'
      )
  );
