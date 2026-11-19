<?php
session_start();

$dbConfigFile = __DIR__ . '/database.php';
if (!is_file($dbConfigFile)) {
    die('Missing database.php. Copy database.example.php to database.php and enter your MySQL credentials.');
}

require $dbConfigFile;

$host = $db_host ?? (getenv('DB_HOST') ?: '127.0.0.1');
$user = $db_user ?? (getenv('DB_USER') ?: 'root');
$pass = $db_pass ?? (getenv('DB_PASS') ?: '');
$dbname = $db_name ?? (getenv('DB_NAME') ?: 'parcel_delivery_system');

// Prefer a configured key in database.php; otherwise use the server environment.
$orsApiKey = !empty($ors_api_key)
    ? trim($ors_api_key)
    : trim((string) (getenv('ORS_API_KEY') ?: ''));

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}`");
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// Lightweight schema upgrade for existing installations.
try {
    $columns = $pdo->query("SHOW COLUMNS FROM parcels")->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'recipient_latitude' => "ALTER TABLE parcels ADD COLUMN recipient_latitude DECIMAL(10,8) DEFAULT NULL AFTER recipient_address",
        'recipient_longitude' => "ALTER TABLE parcels ADD COLUMN recipient_longitude DECIMAL(11,8) DEFAULT NULL AFTER recipient_latitude",
        'recipient_geocoded_at' => "ALTER TABLE parcels ADD COLUMN recipient_geocoded_at TIMESTAMP NULL DEFAULT NULL AFTER recipient_longitude",
    ];
    foreach ($needed as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
        }
    }
    if (!$pdo->query("SHOW TABLES LIKE 'delivery_route_snapshots'")->fetchColumn()) {
        $pdo->exec("CREATE TABLE delivery_route_snapshots (
            parcel_id INT PRIMARY KEY,
            rider_id INT NOT NULL,
            start_latitude DECIMAL(10,8) NOT NULL,
            start_longitude DECIMAL(11,8) NOT NULL,
            end_latitude DECIMAL(10,8) NOT NULL,
            end_longitude DECIMAL(11,8) NOT NULL,
            distance_km DECIMAL(10,2) DEFAULT NULL,
            duration_min DECIMAL(10,1) DEFAULT NULL,
            route_geometry LONGTEXT DEFAULT NULL,
            calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
            FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$pdo->query("SHOW TABLES LIKE 'parcel_route_plans'")->fetchColumn()) {
        $pdo->exec("CREATE TABLE parcel_route_plans (
            parcel_id INT PRIMARY KEY, rider_id INT NOT NULL,
            start_latitude DECIMAL(10,8) NOT NULL, start_longitude DECIMAL(11,8) NOT NULL,
            end_latitude DECIMAL(10,8) NOT NULL, end_longitude DECIMAL(11,8) NOT NULL,
            distance_km DECIMAL(10,2) DEFAULT NULL, duration_min DECIMAL(10,1) DEFAULT NULL,
            route_geometry LONGTEXT NOT NULL,
            backup_distance_km DECIMAL(10,2) DEFAULT NULL, backup_duration_min DECIMAL(10,1) DEFAULT NULL,
            backup_route_geometry LONGTEXT DEFAULT NULL, planned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
            FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $planColumns = $pdo->query("SHOW COLUMNS FROM parcel_route_plans")->fetchAll(PDO::FETCH_COLUMN);
    $planUpgrades = [
        'backup_distance_km' => 'ALTER TABLE parcel_route_plans ADD COLUMN backup_distance_km DECIMAL(10,2) DEFAULT NULL AFTER route_geometry',
        'backup_duration_min' => 'ALTER TABLE parcel_route_plans ADD COLUMN backup_duration_min DECIMAL(10,1) DEFAULT NULL AFTER backup_distance_km',
        'backup_route_geometry' => 'ALTER TABLE parcel_route_plans ADD COLUMN backup_route_geometry LONGTEXT DEFAULT NULL AFTER backup_duration_min',
    ];
    foreach ($planUpgrades as $column => $sql) {
        if (!in_array($column, $planColumns, true)) {
            $pdo->exec($sql);
        }
    }
    if (!$pdo->query("SHOW TABLES LIKE 'parcel_route_history'")->fetchColumn()) {
        $pdo->exec("CREATE TABLE parcel_route_history (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, parcel_id INT NOT NULL, rider_id INT NOT NULL,
            latitude DECIMAL(10,8) NOT NULL, longitude DECIMAL(11,8) NOT NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_route_history_parcel_time (parcel_id, recorded_at),
            FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
            FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    // Keep the riders table consistent: only users with the rider role may have a rider profile.
    // This also repairs older demo databases that accidentally inserted the admin into riders.
    try {
        $pdo->exec("DELETE r FROM riders r INNER JOIN users u ON u.id = r.user_id WHERE u.role <> 'rider'");
    } catch (Throwable $e) {
        // Ignore cleanup failures; the role filters in the queries below still prevent admin users from appearing as riders.
    }
} catch (Throwable $e) {
    // Fresh installs get the columns from database.sql. Existing installs can retry on next request.
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf(): void
{
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        throw new RuntimeException('Invalid CSRF token.');
    }
}

function sanitize_string($value): string
{
    return trim(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_auth(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function is_admin(): bool
{
    return !empty($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_rider(): bool
{
    return !empty($_SESSION['role']) && $_SESSION['role'] === 'rider';
}

function current_user(): ?array
{
    global $pdo;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function ensure_rider_profile(): ?array
{
    global $pdo;

    $user = current_user();
    if (!$user) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM riders WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    return $stmt->fetch();
}

function ors_request(string $endpoint, array $params = []): array
{
    global $orsApiKey;

    if (!$orsApiKey) {
        return ['ok' => false, 'message' => 'OpenRouteService API key is not configured.'];
    }

    $params['api_key'] = $orsApiKey;
    $url = 'https://api.openrouteservice.org' . $endpoint . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return ['ok' => false, 'message' => 'Could not contact OpenRouteService.'];
    }

    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($data)) {
        return [
            'ok' => false,
            'message' => $data['error']['message'] ?? 'OpenRouteService returned an error.',
            'http_code' => $httpCode
        ];
    }

    return ['ok' => true, 'data' => $data];
}

function ors_post_json(string $endpoint, array $body): array
{
    global $orsApiKey;

    if (!$orsApiKey) {
        return ['ok' => false, 'message' => 'OpenRouteService API key is not configured.'];
    }

    $url = 'https://api.openrouteservice.org' . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $orsApiKey,
            'Content-Type: application/json',
            'Accept: application/geo+json, application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
    error_log('OpenRouteService cURL error: ' . $curlError);

    return [
        'ok' => false,
        'message' => 'Could not contact OpenRouteService.'
    ];
}

    $data = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($data)) {
        return [
            'ok' => false,
            'message' => $data['error']['message'] ?? 'OpenRouteService returned an error.',
            'http_code' => $httpCode
        ];
    }

    return ['ok' => true, 'data' => $data];
}

function geocode_malaysia_address(string $address): ?array
{
    $address = trim($address);
    if ($address === '') {
        return null;
    }

    // Use the same Malaysia-only geocoder as the address search fields. This
    // avoids ambiguous place names being resolved to a location in another country.
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'format' => 'jsonv2',
        'countrycodes' => 'my',
        'limit' => 1,
        'q' => $address,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'CourierFlow Parcel Delivery System/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response ?: '', true);
    if ($httpCode < 200 || $httpCode >= 300 || empty($data[0]['lat']) || empty($data[0]['lon'])) {
        return null;
    }

    $lat = (float) $data[0]['lat'];
    $lng = (float) $data[0]['lon'];
    // A defensive Malaysia bounds check prevents a bad geocoder response from
    // being saved and later sent to the driving-route service.
    if ($lat < 0.8 || $lat > 7.5 || $lng < 99.0 || $lng > 120.0) {
        return null;
    }

    return [
        'lat' => $lat,
        'lng' => $lng,
    ];
}

function get_status_badge(string $status): string
{
    $map = [
        'Pending' => 'badge-pending',
        'Out for Delivery' => 'badge-out',
        'Delivered' => 'badge-delivered',
        'Failed Delivery' => 'badge-failed',
    ];

    return $map[$status] ?? 'badge-pending';
}
