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
    $value = trim(shipmentScalar($_POST[$key] ?? '', str_replace('_', ' ', $key)));
    if (mb_strlen($value) > $maxLength) {
        throw new RuntimeException(ucfirst(str_replace('_', ' ', $key)) . " must be no more than {$maxLength} characters.");
    }
    return $value;
}

function shipmentScalar($value, string $label): string
{
    if (!is_scalar($value)) {
        throw new RuntimeException(ucfirst($label) . ' has an invalid value.');
    }
    return (string) $value;
}

function shipmentRequirePattern(string $value, string $pattern, string $message, bool $optional = false): void
{
    if ($optional && $value === '') {
        return;
    }
    if (!preg_match($pattern, $value)) {
        throw new RuntimeException($message);
    }
}

function shipmentAddress(string $type): array
{
    $posted = $_POST[$type] ?? [];
    if (!is_array($posted)) {
        throw new RuntimeException('Invalid address details.');
    }

    $fields = [
        'name' => 50, 'company' => 80, 'country' => 100, 'postal_code' => 12,
        'state' => 100, 'state_code' => 20, 'city' => 100, 'station_name' => 100,
        'sub_urb' => 100, 'short_address' => 30, 'address_line1' => 100,
        'address_line2' => 100, 'address_line3' => 100, 'email' => 150,
        'phone_type' => 12, 'country_code' => 8, 'mobile' => 30,
        'identification_type' => 40, 'identification_number' => 80,
    ];
    $address = [];
    foreach ($fields as $field => $limit) {
        $address[$field] = trim(shipmentScalar($posted[$field] ?? '', ucfirst($type) . ' ' . str_replace('_', ' ', $field)));
        if (mb_strlen($address[$field]) > $limit) {
            throw new RuntimeException(ucfirst($type) . ': ' . str_replace('_', ' ', $field) . " must be no more than {$limit} characters.");
        }
    }
    $requiredFields = ['name', 'country', 'postal_code', 'state', 'state_code', 'city', 'sub_urb', 'address_line1', 'address_line2', 'mobile'];
    if ($type === 'shipper') {
        $requiredFields[] = 'identification_type';
        $requiredFields[] = 'identification_number';
    }
    foreach ($requiredFields as $required) {
        if ($address[$required] === '') {
            throw new RuntimeException(ucfirst($type) . ': complete all required fields.');
        }
    }
    $countries = [
        'INDIA' => '+91',
        'UNITED STATES' => '+1',
        'UNITED KINGDOM' => '+44',
        'AUSTRALIA' => '+61',
        'CANADA' => '+1',
        'UNITED ARAB EMIRATES' => '+971',
    ];
    if (!isset($countries[$address['country']])) {
        throw new RuntimeException('Select a supported country.');
    }
    if ($type === 'shipper' && $address['country'] !== 'INDIA') {
        throw new RuntimeException('International shipments must originate in INDIA.');
    }
    if ($type === 'receiver' && $address['country'] === 'INDIA') {
        throw new RuntimeException('International shipments cannot be delivered to INDIA.');
    }
    shipmentRequirePattern($address['name'], "/^[A-Za-z][A-Za-z .'-]{1,49}$/", ucfirst($type) . ': name must be 2–50 valid letters.');
    shipmentRequirePattern($address['company'], '/^[A-Za-z0-9 ]{0,80}$/', ucfirst($type) . ': company contains invalid characters.', true);
    shipmentRequirePattern($address['postal_code'], '/^[0-9]+$/', ucfirst($type) . ': postal code must contain digits only.');
    $postalPatterns = [
        'INDIA' => '/^[1-9][0-9]{5}$/',
        'UNITED STATES' => '/^[0-9]{5}$/',
        'UNITED KINGDOM' => '/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/i',
        'AUSTRALIA' => '/^[0-9]{4}$/',
        'CANADA' => '/^[A-Z][0-9][A-Z] ?[0-9][A-Z][0-9]$/i',
        'UNITED ARAB EMIRATES' => '/^[0-9]{5}$/',
    ];
    shipmentRequirePattern(
        $address['postal_code'],
        $postalPatterns[$address['country']],
        ucfirst($type) . ': postal code does not match the selected country.'
    );
    foreach (['state', 'city', 'sub_urb'] as $field) {
        shipmentRequirePattern($address[$field], "/^[A-Za-z][A-Za-z .'-]*$/", ucfirst($type) . ': ' . str_replace('_', ' ', $field) . ' must contain letters only.');
    }
    if ($address['station_name'] !== '') {
        shipmentRequirePattern($address['station_name'], "/^[A-Za-z][A-Za-z .'-]*$/", ucfirst($type) . ': station name must contain letters only.');
    }
    foreach (['short_address' => '/^[A-Za-z0-9 ]{1,30}$/', 'address_line1' => '/^[A-Za-z0-9 ,.\-\/#()]{1,100}$/', 'address_line2' => '/^[A-Za-z0-9 ,.\-\/#()]{1,100}$/', 'address_line3' => '/^[A-Za-z0-9 ,.\-\/#()]{0,100}$/'] as $field => $pattern) {
        shipmentRequirePattern($address[$field], $pattern, ucfirst($type) . ': invalid ' . str_replace('_', ' ', $field) . '.', $field === 'short_address' || $field === 'address_line3');
    }
    if (!in_array($address['phone_type'], ['Mobile', 'Landline'], true)) {
        throw new RuntimeException('Select a valid phone type.');
    }
    if (!in_array($address['country_code'], array_unique(array_values($countries)), true)) {
        throw new RuntimeException(ucfirst($type) . ': select a valid country code.');
    }
    if ($address['country_code'] !== $countries[$address['country']]) {
        throw new RuntimeException(ucfirst($type) . ': country calling code must match the selected country.');
    }
    shipmentRequirePattern($address['mobile'], '/^[0-9]{10}$/', ucfirst($type) . ': mobile number must contain exactly 10 digits.');
    if ($address['country'] === 'INDIA' && !preg_match('/^[6-9][0-9]{9}$/', $address['mobile'])) {
        throw new RuntimeException(ucfirst($type) . ': Indian mobile number must start with 6, 7, 8, or 9.');
    }
    shipmentRequirePattern($address['state_code'], '/^[A-Za-z0-9]{1,20}$/', ucfirst($type) . ': select or enter a valid state code.');
    if ($address['email'] !== '' && !filter_var($address['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(ucfirst($type) . ': enter a valid email address.');
    }
    $idTypes = $type === 'shipper'
        ? ['Aadhaar Number', 'PAN', 'Passport']
        : ['Aadhaar Number', 'PAN', 'Passport', 'Driving License', 'Voter ID'];
    if ($address['identification_type'] !== '' && !in_array($address['identification_type'], $idTypes, true)) {
        throw new RuntimeException('Select a valid identification type.');
    }
    $identificationRules = [
        'Aadhaar Number' => '/^[0-9]{12}$/',
        'PAN' => '/^[A-Z]{5}[0-9]{4}[A-Z]$/',
        'Passport' => '/^[A-Z][A-Z0-9]{7}$/',
        'Driving License' => '/^[A-Z0-9]{8,16}$/',
        'Voter ID' => '/^[A-Z0-9]{10}$/',
    ];
    $address['identification_number'] = strtoupper($address['identification_number']);
    if ($type === 'shipper' && (!isset($identificationRules[$address['identification_type']])
        || !preg_match($identificationRules[$address['identification_type']], $address['identification_number']))) {
        throw new RuntimeException('Shipper: identification number does not match Aadhaar, PAN, or Passport format.');
    }
    if ($type === 'receiver' && $address['identification_number'] !== ''
        && (!isset($identificationRules[$address['identification_type']])
            || !preg_match($identificationRules[$address['identification_type']], $address['identification_number']))) {
        throw new RuntimeException(ucfirst($type) . ': identification number does not match the selected type.');
    }
    foreach (['latitude', 'longitude'] as $coordinate) {
        $raw = trim(shipmentScalar($posted[$coordinate] ?? '0', ucfirst($type) . ' ' . $coordinate));
        if ($raw === '') {
            $raw = '0';
        }
        shipmentRequirePattern($raw, '/^-?[0-9]+(?:\.[0-9]{1,2})?$/', ucfirst($type) . ': coordinates must be decimals with up to 2 decimal places.');
        $number = (float) $raw;
        if (($coordinate === 'latitude' && ($number < -90 || $number > 90))
            || ($coordinate === 'longitude' && ($number < -180 || $number > 180))) {
            throw new RuntimeException(ucfirst($type) . ': coordinates are out of range.');
        }
        $address[$coordinate] = $number;
    }
    $address['is_default'] = isset($posted['is_default']) ? shipmentCheckboxFromValue($posted['is_default'], ucfirst($type) . ' default address') : 0;
    $address['auto_save'] = isset($posted['auto_save']) ? shipmentCheckboxFromValue($posted['auto_save'], ucfirst($type) . ' automatic address book') : 0;
    return $address;
}

function shipmentCheckboxFromValue($value, string $label): int
{
    if (!is_scalar($value) || !in_array((string) $value, ['0', '1', 'on'], true)) {
        throw new RuntimeException('Invalid ' . $label . ' toggle.');
    }
    return in_array((string) $value, ['1', 'on'], true) ? 1 : 0;
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
        $values[] = $address[$column] === ''
            ? (in_array($column, ['identification_type', 'identification_number'], true) ? '' : null)
            : $address[$column];
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

    $piecesRaw = trim(shipmentScalar($_POST['pieces'] ?? '', 'number of pieces'));
    shipmentRequirePattern($piecesRaw, '/^[0-9]+$/', 'Number of pieces must contain digits only.');
    $pieces = (int) $piecesRaw;
    if ($pieces < 1 || $pieces > 99) {
        throw new RuntimeException('Number of pieces must be between 1 and 99.');
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
        $lengthRaw = trim(shipmentScalar($package['length'] ?? '', 'package length'));
        $widthRaw = trim(shipmentScalar($package['width'] ?? '', 'package width'));
        $heightRaw = trim(shipmentScalar($package['height'] ?? '', 'package height'));
        $weightRaw = trim(shipmentScalar($package['actual_wt'] ?? '', 'actual weight'));
        foreach (['Length' => $lengthRaw, 'Width' => $widthRaw, 'Height' => $heightRaw] as $label => $raw) {
            shipmentRequirePattern($raw, '/^[0-9]+(?:\.[0-9]{1,2})?$/', "{$label} must be a decimal with up to 2 decimal places.");
            if ((float) $raw <= 0 || (float) $raw > 999) {
                throw new RuntimeException("{$label} must be greater than 0 and no more than 999.");
            }
        }
        shipmentRequirePattern($weightRaw, '/^[0-9]+(?:\.[0-9]{1,2})?$/', 'Actual weight must be a decimal with up to 2 decimal places.');
        if ((float) $weightRaw <= 0 || (float) $weightRaw > 999.99) {
            throw new RuntimeException('Actual weight must be greater than 0 and no more than 999.99 kg.');
        }
        $length = (float) $lengthRaw;
        $width = (float) $widthRaw;
        $height = (float) $heightRaw;
        $weight = (float) $weightRaw;
        $unit = trim(shipmentScalar($package['unit'] ?? '', 'package unit'));
        if (!in_array($unit, ['CM', 'IN'], true)) {
            throw new RuntimeException('Select a valid package dimension unit.');
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
    $contentsSummarized = shipmentCheckboxFromValue($_POST['contents_summarized'], 'contents summary');
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
        $description = trim(shipmentScalar($commodity['description'] ?? '', 'commodity description'));
        $hsCode = trim(shipmentScalar($commodity['hs_code'] ?? '', 'HS Code'));
        $qtyRaw = trim(shipmentScalar($commodity['qty'] ?? '', 'commodity quantity'));
        $unitPriceRaw = trim(shipmentScalar($commodity['unit_price'] ?? '', 'unit price'));
        if (mb_strlen($description) > 100) {
            throw new RuntimeException('Commodity description must be no more than 100 characters.');
        }
        shipmentRequirePattern($description, '/^[A-Za-z0-9 ,.\-]{1,100}$/', 'Commodity description contains invalid characters.');
        shipmentRequirePattern($hsCode, '/^[0-9]{6,10}$/', 'HS Code must contain 6–10 digits.');
        shipmentRequirePattern($qtyRaw, '/^[0-9]+$/', 'Commodity quantity must contain digits only.');
        $qty = (int) $qtyRaw;
        if ($qty < 1 || $qty > 99999) {
            throw new RuntimeException('Commodity quantity must be between 1 and 99999.');
        }
        shipmentRequirePattern($unitPriceRaw, '/^[0-9]+(?:\.[0-9]{1,2})?$/', 'Unit price must be a decimal with up to 2 decimal places.');
        $unitPrice = (float) $unitPriceRaw;
        if ($unitPrice <= 0 || $unitPrice > 9999999.99) {
            throw new RuntimeException('Unit price must be greater than 0 and no more than 9999999.99.');
        }
        $total = round($qty * $unitPrice, 2);
        $unit = trim(shipmentScalar($commodity['unit'] ?? 'PCS', 'commodity unit'));
        $countryOfOrigin = trim(shipmentScalar($commodity['country_of_origin'] ?? 'INDIA', 'country of origin'));
        if (!in_array($unit, ['PCS', 'KG', 'BOX', 'SET'], true)) {
            throw new RuntimeException('Select a valid commodity unit.');
        }
        if (!in_array($countryOfOrigin, ['INDIA', 'UNITED STATES', 'UNITED KINGDOM', 'AUSTRALIA', 'CANADA', 'UNITED ARAB EMIRATES'], true)) {
            throw new RuntimeException('Select a valid country of origin.');
        }
        $commodityRows[] = compact('description', 'hsCode', 'qty', 'unitPrice', 'total', 'unit', 'countryOfOrigin');
        $totalValue += $total;
    }
    $currency = strtoupper(shipmentInput('currency', 3));
    if (!in_array($currency, ['INR', 'USD', 'EUR', 'GBP', 'AED'], true)) {
        throw new RuntimeException('Select a supported currency.');
    }
    $packageType = shipmentInput('package_type', 20);
    if (!in_array($packageType, ['Docs', 'Non Docs'], true)) {
        throw new RuntimeException('Select a valid package type.');
    }
    $carrier = shipmentInput('carrier', 80);
    $service = shipmentInput('service', 80);
    if (!in_array($carrier, ['Self', 'Other'], true)
        || !in_array($service, ['Express Saver', 'Standard', 'Economy'], true)) {
        throw new RuntimeException('Select a valid carrier and service.');
    }
    $manualInvoiceNo = shipmentInput('manual_invoice_no', 30);
    shipmentRequirePattern($manualInvoiceNo, '/^[A-Za-z0-9 /-]*$/', 'Manual invoice number contains invalid characters.', true);
    $referenceNumber = shipmentInput('reference_number', 30);
    shipmentRequirePattern($referenceNumber, '/^[A-Za-z0-9 ]*$/', 'Reference number contains invalid characters.', true);
    $contents = shipmentInput('contents', 100);
    shipmentRequirePattern($contents, '/^[A-Za-z0-9 ]{1,100}$/', 'Summarize Contents must use letters, numbers, and spaces.');
    $remarks = trim(strip_tags(shipmentScalar($_POST['remarks'] ?? '', 'remarks')));
    if (mb_strlen($remarks) > 250) {
        throw new RuntimeException('Remarks must be no more than 250 characters.');
    }
    $customsStatus = shipmentInput('customs_status', 100);
    if (!in_array($customsStatus, ['Paid', 'UnPaid'], true)) {
        throw new RuntimeException('Select Paid or UnPaid customs status for the international shipment.');
    }
    $customerReference = shipmentInput('customer_reference', 100);
    if (!in_array($customerReference, ['', 'Business', 'Personal'], true)) {
        throw new RuntimeException('Select a valid customer reference.');
    }
    $termsOfDelivery = shipmentInput('terms_of_delivery', 255);
    if (!in_array($termsOfDelivery, ['BEING SENDING FOR GIFT PURPOSE AND NOT FOR SALE', 'Commercial shipment', 'Personal effects'], true)) {
        throw new RuntimeException('Select valid terms of delivery.');
    }
    $packaging = shipmentInput('packaging', 100);
    if (!in_array($packaging, ['Use My Own Package', 'Box', 'Envelope', 'Pallet'], true)) {
        throw new RuntimeException('Select valid packaging.');
    }
    $termOfInvoice = shipmentInput('term_of_invoice', 100);
    $exportReason = shipmentInput('export_reason', 100);
    if (!in_array($termOfInvoice, ['DDU', 'DDP', 'DAP'], true)
        || !in_array($exportReason, ['Gifts', 'Sample', 'Commercial', 'Personal', 'Return'], true)) {
        throw new RuntimeException('Select valid performa details.');
    }
    $shipMethod = shipmentInput('ship_method', 40);
    if (!in_array($shipMethod, ['Schedule a Pick Up', 'Drop Off at Carrier'], true)) {
        throw new RuntimeException('Select a valid shipping method.');
    }
    $pickupDatetime = null;
    $pickupSlot = null;
    $pickupContactName = '';
    $pickupContactPhone = '';
    $pickupInstructions = '';
    if ($shipMethod === 'Schedule a Pick Up') {
        $date = shipmentInput('pickup_date', 10);
        $pickupSlot = shipmentInput('pickup_time', 20);
        if (!in_array($pickupSlot, ['09:00-12:00', '12:00-15:00', '15:00-18:00'], true)) {
            throw new RuntimeException('Select a valid pickup time slot.');
        }
        $today = new DateTimeImmutable('today');
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date
            || $parsedDate < $today || $parsedDate > $today->modify('+30 days')) {
            throw new RuntimeException('Pickup date must be today or within the next 30 days.');
        }
        $pickupContactName = shipmentInput('pickup_contact_name', 50);
        shipmentRequirePattern($pickupContactName, "/^[A-Za-z][A-Za-z .'-]{1,49}$/", 'Pickup contact name must be 2–50 valid letters.');
        $pickupContactPhone = shipmentInput('pickup_contact_phone', 10);
        shipmentRequirePattern($pickupContactPhone, '/^[0-9]{10}$/', 'Pickup contact phone must contain exactly 10 digits.');
        $pickupInstructions = trim(strip_tags(shipmentScalar($_POST['pickup_instructions'] ?? '', 'pickup instructions')));
        if (mb_strlen($pickupInstructions) > 200) {
            throw new RuntimeException('Pickup instructions must be no more than 200 characters.');
        }
        $time = substr($pickupSlot, 0, 5);
        $parsed = DateTime::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        if (!$parsed) {
            throw new RuntimeException('Select a valid pickup date and time.');
        }
        $pickupDatetime = $parsed->format('Y-m-d H:i:s');
    }

    $gstInvoiceValue = shipmentScalar($_POST['gst_invoice'] ?? '0', 'GST invoice');
    if (!in_array($gstInvoiceValue, ['0', '1'], true)) {
        throw new RuntimeException('Select Yes or No for GST Invoice.');
    }
    $gstInvoice = $gstInvoiceValue === '1';
    $gstin = strtoupper(shipmentInput('gstin', 15));
    $gstLegalName = shipmentInput('gst_legal_name', 80);
    $gstAmount = trim((string) ($_POST['gst_amount'] ?? ''));
    $gstIgst = trim((string) ($_POST['gst_igst'] ?? ''));
    if ($gstInvoice) {
        shipmentRequirePattern($gstin, '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', 'Enter a valid 15-character GSTIN.');
        shipmentRequirePattern($gstLegalName, '/^[A-Za-z0-9 ]{1,80}$/', 'GST Legal Name is required and must be alphanumeric.');
    } elseif ($gstin !== '') {
        shipmentRequirePattern($gstin, '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', 'Enter a valid 15-character GSTIN.');
    }
    foreach (['GST Amount' => $gstAmount, 'IGST' => $gstIgst] as $label => $value) {
        if ($value !== '') {
            shipmentRequirePattern($value, '/^[0-9]+(?:\.[0-9]{1,2})?$/', "{$label} must be a decimal with up to 2 decimal places.");
            if ((float) $value < 0 || (float) $value > 100) {
                throw new RuntimeException("{$label} must be from 0 to 100.");
            }
        }
    }

    $invoice = $_FILES['own_invoice'] ?? null;
    $invoiceEnabled = !empty($_POST['own_invoice_enabled']);
    if ($invoiceEnabled && (!$invoice || $invoice['error'] === UPLOAD_ERR_NO_FILE)) {
        throw new RuntimeException('Choose a PDF invoice when Upload My Own Invoice is enabled.');
    }
    if ($invoiceEnabled && $invoice && $invoice['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($invoice['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Invoice upload failed. Please choose the PDF again.');
        }
        if ((int) $invoice['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('Invoice file must be no larger than 2 MB.');
        }
        if (strtolower(pathinfo((string) $invoice['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('Only files with a .pdf extension are allowed.');
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
        $fileName = 'invoice_' . bin2hex(random_bytes(16)) . '.pdf';
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
        'manual_invoice_no' => $manualInvoiceNo,
        'reference_number' => $referenceNumber,
        'currency' => $currency,
        'rate_fetch_type' => shipmentInput('rate_fetch_type', 80),
        'contents' => $contents,
        'contents_summarized' => $contentsSummarized,
        'remarks' => $remarks,
        'customer_reference' => $customerReference,
        'customs_status' => $customsStatus,
        'tds' => isset($_POST['tds']) ? shipmentCheckboxFromValue($_POST['tds'], 'TDS') : 0,
        'commercial' => isset($_POST['commercial']) ? shipmentCheckboxFromValue($_POST['commercial'], 'Commercial') : 0,
        'terms_of_delivery' => $termsOfDelivery,
        'packaging' => $packaging,
        'actual_wt' => round($actualWeight, 3),
        'vol_wt' => round($volWeight, 3),
        'chg_wt' => round($chargeableWeight, 3),
        'csb_type' => 'CSB 4',
        'term_of_invoice' => $termOfInvoice,
        'export_reason' => $exportReason,
        'gst_invoice' => $gstInvoice ? 1 : 0,
        'gstin' => $gstin,
        'gst_legal_name' => $gstLegalName === '' ? null : $gstLegalName,
        'gst_amount' => $gstAmount === '' ? null : (float) $gstAmount,
        'gst_igst' => $gstIgst === '' ? null : (float) $gstIgst,
        'gst_details' => trim(strip_tags(shipmentScalar($_POST['gst_details'] ?? '', 'GST details'))),
        'ship_method' => $shipMethod,
        'pickup_datetime' => $pickupDatetime,
        'pickup_slot' => $pickupSlot,
        'pickup_contact_name' => $pickupContactName === '' ? null : $pickupContactName,
        'pickup_contact_phone' => $pickupContactPhone === '' ? null : $pickupContactPhone,
        'pickup_instructions' => $pickupInstructions === '' ? null : $pickupInstructions,
        'own_invoice_path' => $uploadedPath,
        'total_value' => round($totalValue, 2),
    ];

    $pdo->beginTransaction();
    $userId = (int) $_SESSION['user_id'];
    $trackingNo = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'IN' . date('Ymd') . random_int(100000, 999999);
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

    $columns = ['tracking_no', 'user_id', 'shipment_type', 'status'];
    $values = [$trackingNo, $userId, 'international', 'Booked'];
    foreach (['shipper' => $shipper, 'receiver' => $receiver] as $prefix => $address) {
        foreach ([
            'name', 'company', 'country', 'postal_code', 'state', 'state_code', 'city',
            'station_name', 'sub_urb', 'short_address', 'latitude', 'longitude',
            'address_line1', 'address_line2', 'address_line3', 'email', 'phone_type',
            'country_code', 'mobile', 'identification_type', 'identification_number',
        ] as $field) {
            $columns[] = $prefix . '_' . $field;
            if ($prefix === 'receiver' && in_array($field, ['identification_type', 'identification_number'], true)
                && $address[$field] === '') {
                $values[] = 'Not Provided';
            } else {
                $values[] = $address[$field] === '' ? null : $address[$field];
            }
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
    $commodityInsert = $pdo->prepare('INSERT INTO shipment_commodities (shipment_id, description, hs_code, qty, unit_price, total, unit, country_of_origin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($commodityRows as $commodity) {
        $commodityInsert->execute([
            $shipmentId, $commodity['description'], $commodity['hsCode'] ?: null,
            $commodity['qty'], $commodity['unitPrice'], $commodity['total'],
            $commodity['unit'], $commodity['countryOfOrigin'],
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
    $oldInput = $_POST;
    unset($oldInput['csrf_token']);
    $_SESSION['shipment_old_input'] = $oldInput;
    $_SESSION['shipment_error'] = $e instanceof RuntimeException && !($e instanceof PDOException)
        ? $e->getMessage()
        : 'Shipment could not be saved. Confirm database_shipments.sql is imported and try again.';
    redirect('create-shipment.php?error=1');
}
