<?php
require_once __DIR__ . '/config.php';
require_auth();

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Only customers and administrators can book a domestic parcel.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

function parcelScalar($value, string $label): string
{
    if (!is_scalar($value)) {
        throw new RuntimeException($label . ' has an invalid value.');
    }
    return trim((string) $value);
}

function parcelText(array $source, string $key, string $label, int $maxLength, bool $required = false): string
{
    $value = parcelScalar($source[$key] ?? '', $label);
    if (mb_strlen($value) > $maxLength) {
        throw new RuntimeException($label . " must be no more than {$maxLength} characters.");
    }
    if ($required && $value === '') {
        throw new RuntimeException($label . ' is required.');
    }
    return $value;
}

function parcelMatch(string $value, string $pattern, string $message, bool $optional = false): void
{
    if ($optional && $value === '') {
        return;
    }
    if (!preg_match($pattern, $value)) {
        throw new RuntimeException($message);
    }
}

function parcelAddress(string $group, string $title): array
{
    $posted = $_POST[$group] ?? null;
    if (!is_array($posted)) {
        throw new RuntimeException($title . ' address is required.');
    }

    $address = [
        'name' => parcelText($posted, 'name', $title . ' name', 50, true),
        'company' => parcelText($posted, 'company', $title . ' company', 80),
        'country' => parcelText($posted, 'country', $title . ' country', 40, true),
        'postal_code' => parcelText($posted, 'postal_code', $title . ' pincode', 6, true),
        'state' => parcelText($posted, 'state', $title . ' state', 80, true),
        'city' => parcelText($posted, 'city', $title . ' city', 80, true),
        'address_line1' => parcelText($posted, 'address_line1', $title . ' address line 1', 150, true),
        'address_line2' => parcelText($posted, 'address_line2', $title . ' address line 2', 150, true),
        'address_line3' => parcelText($posted, 'address_line3', $title . ' address line 3', 150),
        'landmark' => parcelText($posted, 'landmark', $title . ' landmark', 120),
        'email' => parcelText($posted, 'email', $title . ' email', 150),
        'mobile' => parcelText($posted, 'mobile', $title . ' mobile', 10, true),
    ];

    if ($address['country'] !== 'INDIA') {
        throw new RuntimeException($title . ' country must be INDIA for a domestic parcel.');
    }
    parcelMatch($address['name'], "/^[A-Za-z][A-Za-z .'-]{1,49}$/", $title . ' name must contain 2–50 valid letters.');
    parcelMatch($address['company'], '/^[A-Za-z0-9 ]{0,80}$/', $title . ' company may contain only letters, numbers, and spaces.', true);
    parcelMatch($address['postal_code'], '/^[1-9][0-9]{5}$/', $title . ' pincode must be a valid 6-digit Indian pincode.');
    parcelMatch($address['state'], "/^[A-Za-z][A-Za-z .'-]*$/", $title . ' state must contain letters only.');
    parcelMatch($address['city'], "/^[A-Za-z][A-Za-z .'-]*$/", $title . ' city must contain letters only.');
    foreach (['address_line1', 'address_line2', 'address_line3', 'landmark'] as $field) {
        parcelMatch(
            $address[$field],
            '/^[A-Za-z0-9 ,.\-\/#()]*$/',
            $title . ' ' . str_replace('_', ' ', $field) . ' contains unsupported characters.',
            $field === 'address_line3' || $field === 'landmark'
        );
    }
    parcelMatch($address['mobile'], '/^[6-9][0-9]{9}$/', $title . ' mobile must be 10 digits and start with 6, 7, 8, or 9.');
    if ($address['email'] !== '' && !filter_var($address['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException($title . ' email address is invalid.');
    }

    return $address;
}

function parcelDecimal(string $value, string $label, float $maximum): float
{
    parcelMatch($value, '/^[0-9]+(?:\.[0-9]{1,2})?$/', $label . ' must be a decimal with up to 2 places.');
    $number = (float) $value;
    if ($number <= 0 || $number > $maximum) {
        throw new RuntimeException($label . ' is outside the allowed range.');
    }
    return $number;
}

try {
    verify_csrf();

    $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn();
    $ratesExists = (bool) $pdo->query("SHOW TABLES LIKE 'domestic_rates'")->fetchColumn();
    if (!$tableExists || !$ratesExists) {
        throw new RuntimeException('Run database_shipments.sql and database_split.sql before booking.');
    }

    $columns = $pdo->query('SHOW COLUMNS FROM shipments')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['shipment_type', 'shipper_landmark', 'receiver_landmark', 'payment_mode', 'cod_amount', 'declared_value', 'delivery_instructions', 'service_type'] as $requiredColumn) {
        if (!in_array($requiredColumn, $columns, true)) {
            throw new RuntimeException('The shipment split migration is incomplete. Import database_split.sql and try again.');
        }
    }

    $sender = parcelAddress('sender', 'Sender');
    $receiver = parcelAddress('receiver', 'Receiver');
    if ($sender['country'] !== 'INDIA' || $receiver['country'] !== 'INDIA') {
        throw new RuntimeException('Domestic parcels must be shipped from INDIA to INDIA.');
    }

    $packageType = parcelText($_POST, 'package_type', 'Parcel type', 20, true);
    if (!in_array($packageType, ['Document', 'Non-Document'], true)) {
        throw new RuntimeException('Select a valid parcel type.');
    }
    $piecesText = parcelText($_POST, 'pieces', 'Number of pieces', 2, true);
    parcelMatch($piecesText, '/^[0-9]{1,2}$/', 'Number of pieces must be a number from 1 to 99.');
    $pieces = (int) $piecesText;
    if ($pieces < 1 || $pieces > 99) {
        throw new RuntimeException('Number of pieces must be a number from 1 to 99.');
    }

    $contents = parcelText($_POST, 'contents', 'Content description', 100, true);
    parcelMatch($contents, '/^[A-Za-z0-9 ]+$/', 'Content description may contain only letters, numbers, and spaces.');

    $declaredValue = parcelDecimal(parcelText($_POST, 'declared_value', 'Declared value', 12, true), 'Declared value', 9999999999.99);
    $actualWeight = parcelDecimal(parcelText($_POST, 'actual_wt', 'Actual weight', 8, true), 'Actual weight', 999.99);
    $length = parcelDecimal(parcelText($_POST, 'length', 'Length', 8, true), 'Length', 999.99);
    $width = parcelDecimal(parcelText($_POST, 'width', 'Width', 8, true), 'Width', 999.99);
    $height = parcelDecimal(parcelText($_POST, 'height', 'Height', 8, true), 'Height', 999.99);
    $volumetricWeight = round(($length * $width * $height) / 5000, 3);
    $chargeableWeight = max($actualWeight, $volumetricWeight);

    $service = parcelText($_POST, 'service_type', 'Service', 20, true);
    if (!in_array($service, ['Surface', 'Air', 'Express'], true)) {
        throw new RuntimeException('Select a valid domestic service.');
    }
    $rateQuery = $pdo->prepare(
        'SELECT rate FROM domestic_rates WHERE service = ? AND min_wt < ? AND max_wt >= ? ORDER BY min_wt LIMIT 1'
    );
    $rateQuery->execute([$service, $chargeableWeight, $chargeableWeight]);
    $rate = $rateQuery->fetchColumn();
    if ($rate === false) {
        throw new RuntimeException('No rate is configured for the selected service and chargeable weight.');
    }

    $paymentMode = parcelText($_POST, 'payment_mode', 'Payment mode', 20, true);
    if (!in_array($paymentMode, ['Prepaid', 'COD', 'To-Pay'], true)) {
        throw new RuntimeException('Select a valid payment mode.');
    }
    $codText = parcelText($_POST, 'cod_amount', 'COD amount', 12, $paymentMode === 'COD');
    $codAmount = null;
    if ($paymentMode === 'COD') {
        $codAmount = parcelDecimal($codText, 'COD amount', 9999999999.99);
    } elseif ($codText !== '') {
        parcelMatch($codText, '/^[0-9]+(?:\.[0-9]{1,2})?$/', 'COD amount must be a decimal with up to 2 places.');
        $codAmount = (float) $codText;
    }

    $gstin = strtoupper(parcelText($_POST, 'gstin', 'GSTIN', 15));
    parcelMatch(
        $gstin,
        '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
        'Enter a valid 15-character GSTIN.',
        true
    );
    $instructions = strip_tags(parcelText($_POST, 'delivery_instructions', 'Delivery instructions', 200));
    $remarks = strip_tags(parcelText($_POST, 'remarks', 'Remarks', 250));
    $userId = (int) $_SESSION['user_id'];

    $storedPackageType = $packageType === 'Document' ? 'Docs' : 'Non Docs';

    $pdo->beginTransaction();
    $trackingNumber = '';
    $shipmentData = [
        'tracking_no' => '',
        'user_id' => $userId,
        'shipment_type' => 'domestic',
        'status' => 'Booked',
    ];
    foreach (['shipper' => $sender, 'receiver' => $receiver] as $prefix => $address) {
        foreach ([
            'name' => 'name',
            'company' => 'company',
            'country' => 'country',
            'postal_code' => 'postal_code',
            'state' => 'state',
            'city' => 'city',
            'sub_urb' => 'city',
            'landmark' => 'landmark',
            'address_line1' => 'address_line1',
            'address_line2' => 'address_line2',
            'address_line3' => 'address_line3',
            'email' => 'email',
            'mobile' => 'mobile',
        ] as $column => $field) {
            $value = $address[$field];
            $shipmentData[$prefix . '_' . $column] = $value === '' ? null : $value;
        }
        $shipmentData[$prefix . '_country_code'] = '+91';
        $shipmentData[$prefix . '_identification_type'] = 'Domestic';
        $shipmentData[$prefix . '_identification_number'] = 'N/A';
        $shipmentData[$prefix . '_state_code'] = 'IN';
    }
    $shipmentData += [
        'package_type' => $storedPackageType,
        'pieces' => $pieces,
        'carrier' => 'Self',
        'service' => $service,
        'currency' => 'INR',
        'contents' => $contents,
        'contents_summarized' => 1,
        'remarks' => $remarks !== '' ? $remarks : null,
        'customs_status' => 'Domestic',
        'terms_of_delivery' => 'Domestic',
        'packaging' => 'Customer packaging',
        'actual_wt' => $actualWeight,
        'vol_wt' => $volumetricWeight,
        'chg_wt' => $chargeableWeight,
        'csb_type' => 'Domestic',
        'term_of_invoice' => 'Domestic',
        'export_reason' => 'Domestic',
        'gst_invoice' => 0,
        'ship_method' => 'Drop Off at Carrier',
        'total_value' => $declaredValue,
        'payment_mode' => $paymentMode,
        'cod_amount' => $codAmount,
        'declared_value' => $declaredValue,
        'delivery_instructions' => $instructions !== '' ? $instructions : null,
        'service_type' => $service,
        'gstin' => $gstin !== '' ? $gstin : null,
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $trackingNumber = 'DM' . date('Ymd') . random_int(100000, 999999);
        $trackingCheck = $pdo->prepare('SELECT 1 FROM shipments WHERE tracking_no = ?');
        $trackingCheck->execute([$trackingNumber]);
        if (!$trackingCheck->fetchColumn()) {
            break;
        }
        $trackingNumber = '';
    }
    if ($trackingNumber === '') {
        throw new RuntimeException('Could not generate a unique tracking number. Please submit again.');
    }

    $shipmentData['tracking_no'] = $trackingNumber;
    $shipmentColumns = array_keys($shipmentData);
    $insert = $pdo->prepare(
        'INSERT INTO shipments (' . implode(', ', $shipmentColumns) . ')
         VALUES (' . implode(', ', array_fill(0, count($shipmentColumns), '?')) . ')'
    );
    $insert->execute(array_values($shipmentData));

    $shipmentId = (int) $pdo->lastInsertId();
    $packageInsert = $pdo->prepare(
        'INSERT INTO shipment_packages (shipment_id, length, width, height, unit, actual_wt, vol_wt)
         VALUES (?, ?, ?, ?, \'CM\', ?, ?)'
    );
    $packageInsert->execute([$shipmentId, $length, $width, $height, $actualWeight, $volumetricWeight]);

    $historyInsert = $pdo->prepare(
        'INSERT INTO shipment_status_history (shipment_id, status, note) VALUES (?, \'Booked\', ?)'
    );
    $historyInsert->execute([$shipmentId, 'Domestic parcel booked. Estimated rate: INR ' . number_format((float) $rate, 2)]);

    $pdo->commit();
    redirect('shipment-success.php?tracking=' . rawurlencode($trackingNumber));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException) {
        error_log('Domestic parcel booking database error: ' . $e->getMessage());
        $message = 'Unable to save the domestic parcel due to a database error. Verify database_split.sql is applied.';
    } else {
        $message = $e->getMessage();
    }
    $oldInput = $_POST;
    unset($oldInput['csrf_token']);
    $_SESSION['parcel_old_input'] = $oldInput;
    $_SESSION['parcel_error'] = $message;
    redirect('parcels-management.php');
}
