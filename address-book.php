<?php
require_once __DIR__ . '/config.php';
require_auth();
header('Content-Type: application/json; charset=utf-8');

function addressBookResponse(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message] + $data);
    exit;
}

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    addressBookResponse(false, 'Forbidden', [], 403);
}

$allowedFields = [
    'name', 'company', 'country', 'postal_code', 'state', 'state_code', 'city',
    'station_name', 'sub_urb', 'short_address', 'latitude', 'longitude',
    'address_line1', 'address_line2', 'address_line3', 'email', 'phone_type',
    'country_code', 'mobile', 'identification_type', 'identification_number',
];
$requiredFields = [
    'name', 'country', 'postal_code', 'state', 'state_code', 'city', 'sub_urb',
    'address_line1', 'address_line2', 'phone_type', 'country_code', 'mobile',
];

try {
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) $_SESSION['user_id'];

    if ($action === 'list') {
        $type = (string) ($_POST['type'] ?? '');
        if (!in_array($type, ['shipper', 'receiver'], true)) {
            addressBookResponse(false, 'Invalid address type.', [], 422);
        }
        $stmt = $pdo->prepare('SELECT id, name, address_line1, is_default FROM address_book WHERE user_id = ? AND type = ? ORDER BY is_default DESC, name ASC');
        $stmt->execute([$userId, $type]);
        addressBookResponse(true, 'Addresses loaded.', ['addresses' => $stmt->fetchAll()]);
    }

    try {
        verify_csrf();
    } catch (RuntimeException $e) {
        addressBookResponse(false, 'Invalid or expired security token.', [], 403);
    }

    if ($action === 'get') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            addressBookResponse(false, 'Invalid address ID.', [], 422);
        }
        $stmt = $pdo->prepare('SELECT * FROM address_book WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $address = $stmt->fetch();
        if (!$address) {
            addressBookResponse(false, 'Saved address not found.', [], 404);
        }
        unset($address['user_id'], $address['created_at']);
        addressBookResponse(true, 'Address loaded.', ['address' => $address]);
    }

    if ($action === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            addressBookResponse(false, 'Invalid address ID.', [], 422);
        }
        $stmt = $pdo->prepare('DELETE FROM address_book WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        addressBookResponse($stmt->rowCount() > 0, $stmt->rowCount() > 0 ? 'Address deleted.' : 'Saved address not found.', [], $stmt->rowCount() > 0 ? 200 : 404);
    }

    if ($action !== 'save') {
        addressBookResponse(false, 'Unknown address book action.', [], 400);
    }

    $address = json_decode((string) ($_POST['address'] ?? ''), true);
    if (!is_array($address) || !in_array($address['type'] ?? '', ['shipper', 'receiver'], true)) {
        addressBookResponse(false, 'Invalid address data.', [], 422);
    }
    if ($address['type'] === 'shipper') {
        $requiredFields = array_merge($requiredFields, ['identification_type', 'identification_number']);
    }

    $values = [];
    $maxLengths = [
        'name' => 150, 'company' => 150, 'country' => 100, 'postal_code' => 20,
        'state' => 100, 'state_code' => 20, 'city' => 100, 'station_name' => 100,
        'sub_urb' => 100, 'short_address' => 255, 'address_line1' => 255,
        'address_line2' => 255, 'address_line3' => 255, 'email' => 150,
        'phone_type' => 12, 'country_code' => 8, 'mobile' => 30,
        'identification_type' => 40, 'identification_number' => 80,
    ];
    foreach ($allowedFields as $field) {
        $value = trim((string) ($address[$field] ?? ''));
        if (strlen($value) > $maxLengths[$field]) {
            addressBookResponse(false, 'One or more address fields are too long.', [], 422);
        }
        if (in_array($field, $requiredFields, true) && $value === '') {
            addressBookResponse(false, 'Complete all required address fields before saving.', [], 422);
        }
        if (in_array($field, ['latitude', 'longitude'], true)) {
            if ($value === '') {
                $value = '0';
            }
            if (!is_numeric($value)) {
                addressBookResponse(false, 'Latitude and longitude must be numbers.', [], 422);
            }
        }
        $values[$field] = $value === ''
            ? (in_array($field, ['identification_type', 'identification_number'], true) ? '' : null)
            : $value;
    }
    if (!filter_var($values['email'] ?? '', FILTER_VALIDATE_EMAIL) && !empty($values['email'])) {
        addressBookResponse(false, 'Enter a valid address email.', [], 422);
    }
    if (!in_array($values['country'], ['INDIA', 'UNITED STATES', 'UNITED KINGDOM', 'AUSTRALIA', 'CANADA', 'UNITED ARAB EMIRATES'], true)
        || !in_array($values['phone_type'], ['Mobile', 'Landline'], true)
        || !preg_match('/^\+[0-9]{1,4}$/', (string) $values['country_code'])) {
        addressBookResponse(false, 'Select a valid country, phone type, and calling code.', [], 422);
    }
    $postalPatterns = [
        'INDIA' => '/^[1-9][0-9]{5}$/',
        'UNITED STATES' => '/^[0-9]{5}$/',
        'UNITED KINGDOM' => '/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/i',
        'AUSTRALIA' => '/^[0-9]{4}$/',
        'CANADA' => '/^[A-Z][0-9][A-Z] ?[0-9][A-Z][0-9]$/i',
        'UNITED ARAB EMIRATES' => '/^[0-9]{5}$/',
    ];
    if (!preg_match($postalPatterns[$values['country']], (string) $values['postal_code'])) {
        addressBookResponse(false, 'Postal code does not match the selected country.', [], 422);
    }
    foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $coordinate => [$minimum, $maximum]) {
        if ((float) $values[$coordinate] < $minimum || (float) $values[$coordinate] > $maximum) {
            addressBookResponse(false, 'Latitude or longitude is out of range.', [], 422);
        }
    }
    if (($values['identification_type'] ?? '') === 'Aadhaar Number'
        && !preg_match('/^\d{12}$/', (string) $values['identification_number'])) {
        addressBookResponse(false, 'Aadhaar number must contain 12 digits.', [], 422);
    }

    $isDefault = !empty($address['is_default']) ? 1 : 0;
    $pdo->beginTransaction();
    if ($isDefault) {
        $pdo->prepare('UPDATE address_book SET is_default = 0 WHERE user_id = ? AND type = ?')
            ->execute([$userId, $address['type']]);
    }
    $columns = array_merge(['user_id', 'type'], array_keys($values), ['is_default']);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO address_book (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$userId, $address['type']], array_values($values), [$isDefault]));
    $pdo->commit();
    addressBookResponse(true, 'Address saved.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Address book request failed: ' . $e->getMessage());
    addressBookResponse(false, 'Address book is unavailable. Confirm the shipment database script has been imported.', [], 500);
}
