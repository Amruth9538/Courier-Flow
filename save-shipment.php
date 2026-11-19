<?php
require_once __DIR__ . '/config.php';
require_auth();

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Only customers and administrators can book a shipment.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

function shipmentInput(string $key, int $maxLength = 255): string
{
    $value = trim(strip_tags((string) ($_POST[$key] ?? '')));
    return substr($value, 0, $maxLength);
}

function shipmentAddress(string $type): array
{
    $posted = $_POST[$type] ?? [];
    if (!is_array($posted)) {
        throw new RuntimeException('Invalid address details.');
    }

    $fields = [
        'name' => 150, 'company' => 150, 'country' => 100, 'postal_code' => 20,
        'state' => 100, 'state_code' => 20, 'city' => 100, 'station_name' => 100,
        'sub_urb' => 100, 'short_address' => 255, 'address_line1' => 255,
        'address_line2' => 255, 'address_line3' => 255, 'email' => 150,
        'phone_type' => 12, 'country_code' => 8, 'mobile' => 30,
        'identification_type' => 40, 'identification_number' => 80,
    ];
    $address = [];
    foreach ($fields as $field => $limit) {
        $address[$field] = substr(trim(strip_tags((string) ($posted[$field] ?? ''))), 0, $limit);
    }
    foreach (['name', 'country', 'postal_code', 'state', 'state_code', 'city', 'sub_urb', 'address_line1', 'address_line2', 'mobile', 'identification_type', 'identification_number'] as $required) {
        if ($address[$required] === '') {
            throw new RuntimeException(ucfirst($type) . ': complete all required fields.');
        }
    }
    $countries = ['INDIA' => '+91', 'UNITED STATES' => '+1', 'UNITED KINGDOM' => '+44', 'AUSTRALIA' => '+61', 'CANADA' => '+1'];
    if (!isset($countries[$address['country']])) {
        throw new RuntimeException('Select a supported country.');
    }
    if ($address['country'] === 'INDIA' && !preg_match('/^\d{6}$/', $address['postal_code'])) {
        throw new RuntimeException(ucfirst($type) . ': India postal code must contain six digits.');
    }
    if (!in_array($address['phone_type'], ['Mobile', 'Landline'], true)) {
        throw new RuntimeException('Select a valid phone type.');
    }
    if (!preg_match('/^\+[0-9]{1,4}$/', $address['country_code']) || !preg_match('/^[0-9 ()-]{5,30}$/', $address['mobile'])) {
        throw new RuntimeException(ucfirst($type) . ': enter a valid phone number and country code.');
    }
    if ($address['email'] !== '' && !filter_var($address['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(ucfirst($type) . ': enter a valid email address.');
    }
    $idTypes = ['Aadhaar Number', 'PAN', 'Passport', 'Driving License', 'Voter ID'];
    if (!in_array($address['identification_type'], $idTypes, true)) {
        throw new RuntimeException('Select a valid identification type.');
    }
    if ($address['identification_type'] === 'Aadhaar Number'
        && !preg_match('/^\d{12}$/', $address['identification_number'])) {
        throw new RuntimeException(ucfirst($type) . ': Aadhaar number must contain 12 digits.');
    }
    foreach (['latitude', 'longitude'] as $coordinate) {
        $raw = trim((string) ($posted[$coordinate] ?? '0'));
        if ($raw === '') {
            $raw = '0';
        }
        if (!is_numeric($raw)) {
            throw new RuntimeException(ucfirst($type) . ': coordinates must be numbers.');
        }
        $number = (float) $raw;
        if (($coordinate === 'latitude' && ($number < -90 || $number > 90))
            || ($coordinate === 'longitude' && ($number < -180 || $number > 180))) {
            throw new RuntimeException(ucfirst($type) . ': coordinates are out of range.');
        }
        $address[$coordinate] = $number;
    }
    $address['is_default'] = !empty($posted['is_default']) ? 1 : 0;
    $address['auto_save'] = !empty($posted['auto_save']);
    return $address;
}

function saveShipmentAddress(PDO $pdo, int $userId, string $type, array $address): void
{
    if (empty($address['auto_save'])) {
        return;
    }
    if (!empty($address['is_default'])) {
        $pdo->prepare('UPDATE address_book SET is_default = 0 WHERE user_id = ? AND type = ?')
            ->execute([$userId, $type]);
    }
    $columns = [
        'user_id', 'type', 'name', 'company', 'country', 'postal_code', 'state',
        'state_code', 'city', 'station_name', 'sub_urb', 'short_address',
        'latitude', 'longitude', 'address_line1', 'address_line2', 'address_line3',
        'email', 'phone_type', 'country_code', 'mobile', 'identification_type',
        'identification_number', 'is_default',
    ];
    $values = [$userId, $type];
    foreach (array_slice($columns, 2, -1) as $column) {
        $values[] = $address[$column] === '' ? null : $address[$column];
    }
    $values[] = (int) $address['is_default'];
    $stmt = $pdo->prepare('INSERT INTO address_book (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);
}

$uploadedPath = null;
try {
    verify_csrf();
    $shipper = shipmentAddress('shipper');
    $receiver = shipmentAddress('receiver');

    $pieces = filter_var($_POST['pieces'] ?? null, FILTER_VALIDATE_INT);
    if ($pieces === false || $pieces < 1 || $pieces > 50) {
        throw new RuntimeException('Number of pieces must be between 1 and 50.');
    }
    $packages = $_POST['packages'] ?? [];
    if (!is_array($packages) || count($packages) !== $pieces) {
        throw new RuntimeException('Enter dimensions and weight for every package piece.');
    }
    $packageRows = [];
    $actualWeight = 0.0;
    $volWeight = 0.0;
    foreach (array_values($packages) as $package) {
        if (!is_array($package)) {
            throw new RuntimeException('Invalid package details.');
        }
        $length = filter_var($package['length'] ?? null, FILTER_VALIDATE_FLOAT);
        $width = filter_var($package['width'] ?? null, FILTER_VALIDATE_FLOAT);
        $height = filter_var($package['height'] ?? null, FILTER_VALIDATE_FLOAT);
        $weight = filter_var($package['actual_wt'] ?? null, FILTER_VALIDATE_FLOAT);
        $unit = (string) ($package['unit'] ?? '');
        if ($length === false || $width === false || $height === false || $weight === false
            || $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0
            || !in_array($unit, ['CM', 'IN'], true)) {
            throw new RuntimeException('Each package needs positive dimensions and actual weight.');
        }
        $cm = $unit === 'IN' ? 2.54 : 1;
        $volume = ($length * $cm * $width * $cm * $height * $cm) / 5000;
        $packageRows[] = [
            'length' => $length, 'width' => $width, 'height' => $height,
            'unit' => $unit, 'actual_wt' => $weight, 'vol_wt' => $volume,
        ];
        $actualWeight += $weight;
        $volWeight += $volume;
    }
    $chargeableWeight = max($actualWeight, $volWeight);

    $contents = shipmentInput('contents');
    if ($contents === '') {
        throw new RuntimeException('Summarize the shipment contents.');
    }
    if (empty($_POST['contents_summarized'])) {
        throw new RuntimeException('Confirm that the shipment contents have been summarized.');
    }
    $commodities = $_POST['commodities'] ?? [];
    if (!is_array($commodities) || count($commodities) < 1 || count($commodities) > 100) {
        throw new RuntimeException('Add at least one commodity.');
    }
    $commodityRows = [];
    $totalValue = 0.0;
    foreach ($commodities as $commodity) {
        if (!is_array($commodity)) {
            throw new RuntimeException('Invalid commodity details.');
        }
        $description = substr(trim(strip_tags((string) ($commodity['description'] ?? ''))), 0, 255);
        $hsCode = substr(trim(strip_tags((string) ($commodity['hs_code'] ?? ''))), 0, 30);
        $qty = filter_var($commodity['qty'] ?? null, FILTER_VALIDATE_FLOAT);
        $unitPrice = filter_var($commodity['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($description === '' || $qty === false || $qty <= 0 || $unitPrice === false || $unitPrice < 0) {
            throw new RuntimeException('Each commodity needs a description, positive quantity, and non-negative price.');
        }
        $total = round($qty * $unitPrice, 2);
        $commodityRows[] = compact('description', 'hsCode', 'qty', 'unitPrice', 'total');
        $totalValue += $total;
    }
    $currency = strtoupper(shipmentInput('currency', 3));
    if (!in_array($currency, ['INR', 'USD', 'EUR', 'GBP'], true)) {
        throw new RuntimeException('Select a supported currency.');
    }
    $packageType = shipmentInput('package_type', 20);
    if (!in_array($packageType, ['Docs', 'Non Docs'], true)) {
        throw new RuntimeException('Select a valid package type.');
    }
    $carrier = shipmentInput('carrier', 80);
    $service = shipmentInput('service', 80);
    if ($carrier === '' || $service === '') {
        throw new RuntimeException('Select a carrier and service.');
    }
    $shipMethod = shipmentInput('ship_method', 40);
    if (!in_array($shipMethod, ['Schedule a Pick Up', 'Drop Off at Carrier'], true)) {
        throw new RuntimeException('Select a valid shipping method.');
    }
    $pickupDatetime = null;
    if ($shipMethod === 'Schedule a Pick Up') {
        $date = shipmentInput('pickup_date', 10);
        $time = shipmentInput('pickup_time', 5);
        $parsed = DateTime::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        if (!$parsed || $parsed->format('Y-m-d H:i') !== $date . ' ' . $time) {
            throw new RuntimeException('Select a valid pickup date and time.');
        }
        $pickupDatetime = $parsed->format('Y-m-d H:i:s');
    }

    $invoice = $_FILES['own_invoice'] ?? null;
    if ($invoice && $invoice['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($invoice['error'] !== UPLOAD_ERR_OK || $invoice['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('Invoice must be a PDF no larger than 2 MB.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if ($finfo->file($invoice['tmp_name']) !== 'application/pdf'
            || file_get_contents($invoice['tmp_name'], false, null, 0, 5) !== '%PDF-') {
            throw new RuntimeException('Only a valid PDF invoice can be uploaded.');
        }
        $uploadDirectory = __DIR__ . '/uploads/invoices';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Invoice upload directory could not be created.');
        }
        $fileName = bin2hex(random_bytes(16)) . '.pdf';
        if (!move_uploaded_file($invoice['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
            throw new RuntimeException('Unable to save invoice file.');
        }
        $uploadedPath = 'uploads/invoices/' . $fileName;
    }

    $details = [
        'package_type' => $packageType,
        'pieces' => $pieces,
        'carrier' => $carrier,
        'service' => $service,
        'manual_invoice_no' => shipmentInput('manual_invoice_no', 80),
        'reference_number' => shipmentInput('reference_number', 100),
        'currency' => $currency,
        'rate_fetch_type' => shipmentInput('rate_fetch_type', 80),
        'contents' => $contents,
        'contents_summarized' => !empty($_POST['contents_summarized']) ? 1 : 0,
        'remarks' => trim(strip_tags((string) ($_POST['remarks'] ?? ''))),
        'customer_reference' => shipmentInput('customer_reference', 100),
        'customs_status' => trim(strip_tags((string) ($_POST['customs_status'] ?? ''))),
        'tds' => !empty($_POST['tds']) ? 1 : 0,
        'commercial' => !empty($_POST['commercial']) ? 1 : 0,
        'terms_of_delivery' => shipmentInput('terms_of_delivery', 255),
        'packaging' => shipmentInput('packaging', 100),
        'actual_wt' => round($actualWeight, 3),
        'vol_wt' => round($volWeight, 3),
        'chg_wt' => round($chargeableWeight, 3),
        'csb_type' => shipmentInput('csb_type', 30),
        'term_of_invoice' => shipmentInput('term_of_invoice', 100),
        'export_reason' => shipmentInput('export_reason', 100),
        'gst_invoice' => !empty($_POST['gst_invoice']) ? 1 : 0,
        'gstin' => shipmentInput('gstin', 30),
        'gst_details' => trim(strip_tags((string) ($_POST['gst_details'] ?? ''))),
        'ship_method' => $shipMethod,
        'pickup_datetime' => $pickupDatetime,
        'own_invoice_path' => $uploadedPath,
        'total_value' => round($totalValue, 2),
    ];

    $pdo->beginTransaction();
    $userId = (int) $_SESSION['user_id'];
    $trackingNo = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'CF' . date('Ymd') . random_int(100000, 999999);
        $check = $pdo->prepare('SELECT 1 FROM shipments WHERE tracking_no = ?');
        $check->execute([$candidate]);
        if (!$check->fetchColumn()) {
            $trackingNo = $candidate;
            break;
        }
    }
    if ($trackingNo === '') {
        throw new RuntimeException('Could not generate a unique tracking number. Please try again.');
    }

    $columns = ['tracking_no', 'user_id', 'status'];
    $values = [$trackingNo, $userId, 'Booked'];
    foreach (['shipper' => $shipper, 'receiver' => $receiver] as $prefix => $address) {
        foreach ([
            'name', 'company', 'country', 'postal_code', 'state', 'state_code', 'city',
            'station_name', 'sub_urb', 'short_address', 'latitude', 'longitude',
            'address_line1', 'address_line2', 'address_line3', 'email', 'phone_type',
            'country_code', 'mobile', 'identification_type', 'identification_number',
        ] as $field) {
            $columns[] = $prefix . '_' . $field;
            $values[] = $address[$field] === '' ? null : $address[$field];
        }
    }
    foreach ($details as $column => $value) {
        $columns[] = $column;
        $values[] = $value;
    }
    $stmt = $pdo->prepare('INSERT INTO shipments (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);
    $shipmentId = (int) $pdo->lastInsertId();

    $packageInsert = $pdo->prepare('INSERT INTO shipment_packages (shipment_id, length, width, height, unit, actual_wt, vol_wt) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($packageRows as $package) {
        $packageInsert->execute([
            $shipmentId, $package['length'], $package['width'], $package['height'],
            $package['unit'], $package['actual_wt'], round($package['vol_wt'], 3),
        ]);
    }
    $commodityInsert = $pdo->prepare('INSERT INTO shipment_commodities (shipment_id, description, hs_code, qty, unit_price, total) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($commodityRows as $commodity) {
        $commodityInsert->execute([
            $shipmentId, $commodity['description'], $commodity['hsCode'] ?: null,
            $commodity['qty'], $commodity['unitPrice'], $commodity['total'],
        ]);
    }
    $pdo->prepare('INSERT INTO shipment_status_history (shipment_id, status, note) VALUES (?, ?, ?)')
        ->execute([$shipmentId, 'Booked', 'Shipment booked.']);
    saveShipmentAddress($pdo, $userId, 'shipper', $shipper);
    saveShipmentAddress($pdo, $userId, 'receiver', $receiver);
    $pdo->commit();
    redirect('shipment-success.php?tracking=' . rawurlencode($trackingNo));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($uploadedPath !== null && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadedPath))) {
        unlink(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadedPath));
    }
    error_log('Shipment booking failed: ' . $e->getMessage());
    $_SESSION['shipment_error'] = $e instanceof RuntimeException && !($e instanceof PDOException)
        ? $e->getMessage()
        : 'Shipment could not be saved. Confirm database_shipments.sql is imported and try again.';
    redirect('create-shipment.php?error=1');
}
