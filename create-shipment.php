<?php
require_once __DIR__ . '/config.php';
require_auth();

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Only customers and administrators can book a shipment.');
}
$shipmentSchemaReady = (bool) $pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn();

$userStmt = $pdo->prepare('SELECT full_name, email, phone FROM users WHERE id = ?');
$userStmt->execute([(int) $_SESSION['user_id']]);
$currentUser = $userStmt->fetch() ?: [];
$countries = [
    'INDIA' => ['🇮🇳 INDIA', '+91'],
    'UNITED STATES' => ['🇺🇸 UNITED STATES', '+1'],
    'UNITED KINGDOM' => ['🇬🇧 UNITED KINGDOM', '+44'],
    'AUSTRALIA' => ['🇦🇺 AUSTRALIA', '+61'],
    'CANADA' => ['🇨🇦 CANADA', '+1'],
    'UNITED ARAB EMIRATES' => ['🇦🇪 UNITED ARAB EMIRATES', '+971'],
];
$locations = [
    'states' => [
        'INDIA' => ['Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry'],
        'UNITED STATES' => ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming', 'District of Columbia'],
        'UNITED KINGDOM' => ['England', 'Scotland', 'Wales', 'Northern Ireland'],
        'AUSTRALIA' => ['Australian Capital Territory', 'New South Wales', 'Northern Territory', 'Queensland', 'South Australia', 'Tasmania', 'Victoria', 'Western Australia'],
        'CANADA' => ['Alberta', 'British Columbia', 'Manitoba', 'New Brunswick', 'Newfoundland and Labrador', 'Northwest Territories', 'Nova Scotia', 'Nunavut', 'Ontario', 'Prince Edward Island', 'Quebec', 'Saskatchewan', 'Yukon'],
        'UNITED ARAB EMIRATES' => ['Abu Dhabi', 'Ajman', 'Dubai', 'Fujairah', 'Ras Al Khaimah', 'Sharjah', 'Umm Al Quwain'],
    ],
    'cities' => [
        'INDIA' => ['Ahmedabad', 'Bengaluru', 'Bangalore', 'Bhopal', 'Bhubaneswar', 'Chandigarh', 'Chennai', 'Coimbatore', 'Delhi', 'Gurugram', 'Hyderabad', 'Indore', 'Jaipur', 'Kochi', 'Kolkata', 'Lucknow', 'Mumbai', 'Mysuru', 'New Delhi', 'Noida', 'Pune', 'Surat', 'Thiruvananthapuram', 'Visakhapatnam'],
        'UNITED STATES' => ['Atlanta', 'Austin', 'Boston', 'Chicago', 'Dallas', 'Denver', 'Houston', 'Los Angeles', 'Miami', 'New York', 'Philadelphia', 'Phoenix', 'San Diego', 'San Francisco', 'Seattle', 'Washington'],
        'UNITED KINGDOM' => ['Birmingham', 'Bristol', 'Edinburgh', 'Glasgow', 'Leeds', 'Liverpool', 'London', 'Manchester', 'Newcastle', 'Nottingham', 'Oxford'],
        'AUSTRALIA' => ['Adelaide', 'Brisbane', 'Canberra', 'Darwin', 'Gold Coast', 'Melbourne', 'Perth', 'Sydney'],
        'CANADA' => ['Calgary', 'Edmonton', 'Halifax', 'Montreal', 'Ottawa', 'Quebec City', 'Toronto', 'Vancouver', 'Winnipeg'],
        'UNITED ARAB EMIRATES' => ['Abu Dhabi', 'Ajman', 'Al Ain', 'Dubai', 'Fujairah', 'Ras Al Khaimah', 'Sharjah', 'Umm Al Quwain'],
    ],
];
$idTypes = ['Aadhaar Number', 'PAN', 'Passport', 'Driving License', 'Voter ID'];
$commodityCatalog = [
    ['name' => 'Clothing', 'hs' => '610900', 'price' => '500'],
    ['name' => 'Books', 'hs' => '490100', 'price' => '250'],
    ['name' => 'Documents', 'hs' => '491100', 'price' => '100'],
    ['name' => 'Food products', 'hs' => '210600', 'price' => '350'],
    ['name' => 'Home decor', 'hs' => '392690', 'price' => '750'],
    ['name' => 'Personal effects', 'hs' => '980400', 'price' => '500'],
    ['name' => 'Toys', 'hs' => '950300', 'price' => '400'],
];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$oldInput = $_SESSION['shipment_old_input'] ?? [];
if (!is_array($oldInput)) {
    $oldInput = [];
}
unset($_SESSION['shipment_old_input']);
$oldShipmentError = $_SESSION['shipment_error'] ?? '';
unset($_SESSION['shipment_error']);
$v = static fn (string $name, string $default = ''): string =>
    htmlspecialchars(shipmentFormValue($oldInput, $name, $default), ENT_QUOTES, 'UTF-8');

function shipmentFormValue(array $data, string $name, string $default = ''): string
{
    if (preg_match('/^([^\[]+)(?:\[([^\]]+)\])?$/', $name, $match)) {
        $value = $data[$match[1]] ?? null;
        if (isset($match[2]) && is_array($value)) {
            $value = $value[$match[2]] ?? null;
        }
        return is_scalar($value) ? (string) $value : $default;
    }
    return $default;
}

function shipmentOldChecked(array $data, string $name, bool $default = false): bool
{
    $value = shipmentFormValue($data, $name, $default ? '1' : '0');
    return in_array($value, ['1', 'on', 'yes'], true);
}

function renderAddressCard(string $type, array $countries, array $idTypes, array $currentUser, array $oldInput, array $locations): void
{
    $shipper = $type === 'shipper';
    $title = $shipper ? 'Shipper Details' : 'Receiver Details';
    $prefix = $type;
    $defaultCountry = $shipper ? 'INDIA' : 'UNITED STATES';
    $defaultCode = $shipper ? '+91' : '+1';
    $defaultId = $shipper ? 'Aadhaar Number' : 'Driving License';
    $addressIdTypes = $shipper ? ['Aadhaar Number', 'PAN', 'Passport'] : $idTypes;
    $selectedCountry = shipmentFormValue($oldInput, $prefix . '[country]', $defaultCountry);
    if ($shipper) {
        $selectedCountry = 'INDIA';
    } elseif ($selectedCountry === 'INDIA' || !isset($countries[$selectedCountry])) {
        $selectedCountry = $defaultCountry;
    }
    $name = $shipper ? ($currentUser['full_name'] ?? '') : '';
    $email = $shipper ? ($currentUser['email'] ?? '') : '';
    $phone = $shipper ? ($currentUser['phone'] ?? '') : '';
    $field = static fn (string $key, string $default = ''): string =>
        htmlspecialchars(shipmentFormValue($oldInput, $prefix . '[' . $key . ']', $default), ENT_QUOTES, 'UTF-8');
    ?>
    <section class="shipment-card">
        <div class="shipment-title-row">
            <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="shipment-btn secondary" data-open-address-book="<?= $prefix ?>">Address book</button>
        </div>
        <button type="button" class="shipment-btn secondary shipment-extract" title="Address extraction is not implemented">
            Extract Address (Beta AI)
        </button>
        <!-- TODO: Implement address extraction after selecting a supported provider. -->
        <div class="shipment-fields">
            <div class="shipment-field full">
                <label for="<?= $prefix ?>-name"><?= $shipper ? 'Sender' : 'Recipient' ?> Name <span>*</span></label>
                <input id="<?= $prefix ?>-name" name="<?= $prefix ?>[name]" value="<?= $field('name', (string) $name) ?>" required maxlength="50" minlength="2" data-rule="letters">
                <small class="shipment-required" data-error-for="<?= $prefix ?>-name">Enter 2–50 letters, spaces, dots, hyphens, or apostrophes.</small>
            </div>
            <input type="hidden" name="<?= $prefix ?>[is_default]" value="0">
            <label class="shipment-check shipment-full"><input type="checkbox" name="<?= $prefix ?>[is_default]" value="1" <?= shipmentOldChecked($oldInput, $prefix . '[is_default]') ? 'checked' : '' ?>> Make Default Address</label>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-country">Country/Territory <span>*</span></label>
                <?php if ($shipper): ?>
                    <input id="<?= $prefix ?>-country" value="🇮🇳 INDIA" readonly aria-readonly="true">
                    <input type="hidden" name="<?= $prefix ?>[country]" value="INDIA">
                <?php else: ?>
                    <select id="<?= $prefix ?>-country" name="<?= $prefix ?>[country]" data-country-select required>
                        <?php foreach ($countries as $country => $display): ?>
                            <?php if ($country === 'INDIA') continue; ?>
                            <option value="<?= htmlspecialchars($country, ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars($display[1], ENT_QUOTES, 'UTF-8') ?>" <?= $selectedCountry === $country ? 'selected' : '' ?>><?= htmlspecialchars($display[0], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <small class="shipment-required">Select a country.</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-postal">Postal Code <span>*</span></label>
                <input id="<?= $prefix ?>-postal" name="<?= $prefix ?>[postal_code]" value="<?= $field('postal_code') ?>" data-postal-lookup data-country="<?= htmlspecialchars($selectedCountry, ENT_QUOTES, 'UTF-8') ?>" data-rule="numbers" required maxlength="<?= $selectedCountry === 'UNITED STATES' ? '5' : '10' ?>" inputmode="numeric">
                <small class="shipment-required">Enter a valid postal code for the selected country.</small>
                <small id="<?= $prefix ?>-postal-hint" class="shipment-note"></small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-state">State <span>*</span></label>
                <select id="<?= $prefix ?>-state" name="<?= $prefix ?>[state]" required data-rule="letters" data-location-select="states" data-country="<?= htmlspecialchars($selectedCountry, ENT_QUOTES, 'UTF-8') ?>">
                    <option value="">Select a state</option>
                    <?php foreach ($locations['states'][$selectedCountry] ?? [] as $state): ?><option value="<?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?>" <?= shipmentFormValue($oldInput, $prefix . '[state]') === $state ? 'selected' : '' ?>><?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                </select>
                <small class="shipment-required">Select or enter a valid state.</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-state-code">State Code <span>*</span></label>
                <input id="<?= $prefix ?>-state-code" name="<?= $prefix ?>[state_code]" value="<?= $field('state_code') ?>" required maxlength="20" readonly>
                <small class="shipment-required">Required</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-city">City <span>*</span></label>
                <select id="<?= $prefix ?>-city" name="<?= $prefix ?>[city]" required data-rule="letters" data-location-select="cities" data-country="<?= htmlspecialchars($selectedCountry, ENT_QUOTES, 'UTF-8') ?>">
                    <option value="">Select a city</option>
                    <?php foreach ($locations['cities'][$selectedCountry] ?? [] as $city): ?><option value="<?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?>" <?= shipmentFormValue($oldInput, $prefix . '[city]') === $city ? 'selected' : '' ?>><?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                </select>
                <small class="shipment-required">Enter a valid city.</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-station">Station Name</label>
                <input id="<?= $prefix ?>-station" name="<?= $prefix ?>[station_name]" value="<?= $field('station_name') ?>" maxlength="100" data-rule="letters" readonly>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-suburb">Sub Urb <span>*</span></label>
                <input id="<?= $prefix ?>-suburb" name="<?= $prefix ?>[sub_urb]" value="<?= $field('sub_urb') ?>" required maxlength="100" data-rule="letters">
                <small class="shipment-required">Enter letters only.</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-short-address">Short Address</label>
                <input id="<?= $prefix ?>-short-address" name="<?= $prefix ?>[short_address]" value="<?= $field('short_address') ?>" maxlength="30" data-rule="alnum">
                <small class="shipment-required">Use letters, numbers, and spaces only (max 30).</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-latitude">Latitude</label>
                <input id="<?= $prefix ?>-latitude" name="<?= $prefix ?>[latitude]" type="text" inputmode="decimal" value="<?= $field('latitude', '0.00') ?>" maxlength="6" data-rule="decimal" data-min="-90" data-max="90">
                <small class="shipment-required">Enter a decimal from -90 to 90.</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-longitude">Longitude</label>
                <input id="<?= $prefix ?>-longitude" name="<?= $prefix ?>[longitude]" type="text" inputmode="decimal" value="<?= $field('longitude', '0.00') ?>" maxlength="7" data-rule="decimal" data-min="-180" data-max="180">
                <small class="shipment-required">Enter a decimal from -180 to 180.</small>
            </div>
            <?php for ($line = 1; $line <= 3; $line++): ?>
                <div class="shipment-field <?= $line === 3 ? 'full' : '' ?>">
                    <label for="<?= $prefix ?>-line<?= $line ?>">Address Line <?= $line ?><?= $line < 3 ? ' <span>*</span>' : '' ?></label>
                    <input id="<?= $prefix ?>-line<?= $line ?>" name="<?= $prefix ?>[address_line<?= $line ?>]" value="<?= $field('address_line' . $line) ?>" <?= $line < 3 ? 'required' : '' ?> maxlength="100" data-rule="address">
                    <?php if ($line < 3): ?><small class="shipment-required">Enter a valid address (max 100 characters).</small><?php endif; ?>
                </div>
            <?php endfor; ?>
            <div class="shipment-field full">
                <label for="<?= $prefix ?>-email"><?= $shipper ? 'Sender' : 'Recipient' ?> Email</label>
                <input id="<?= $prefix ?>-email" name="<?= $prefix ?>[email]" type="email" maxlength="150" value="<?= $field('email', (string) $email) ?>" data-rule="email">
                <small class="shipment-required">Enter a valid email address.</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-phone-type">Phone Type <span>*</span></label>
                <select id="<?= $prefix ?>-phone-type" name="<?= $prefix ?>[phone_type]" required><option>Mobile</option><option>Landline</option></select>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-country-code">Country Code <span>*</span></label>
                <select id="<?= $prefix ?>-country-code" name="<?= $prefix ?>[country_code]" required><?php foreach (['+91', '+1', '+44', '+61', '+971'] as $code): ?><option <?= shipmentFormValue($oldInput, $prefix . '[country_code]', $defaultCode) === $code ? 'selected' : '' ?>><?= $code ?></option><?php endforeach; ?></select>
            </div>
            <div class="shipment-field full">
                <label for="<?= $prefix ?>-mobile">Mobile/Cell No <span>*</span></label>
                <input id="<?= $prefix ?>-mobile" name="<?= $prefix ?>[mobile]" value="<?= $field('mobile', (string) $phone) ?>" required maxlength="10" inputmode="numeric" data-rule="numbers">
                <small class="shipment-required">Enter a valid 10-digit mobile number.</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-id-type">Identification Type<?= $shipper ? ' <span>*</span>' : '' ?></label>
                <select id="<?= $prefix ?>-id-type" name="<?= $prefix ?>[identification_type]" <?= $shipper ? 'required' : '' ?>>
                    <?php foreach ($addressIdTypes as $idType): ?>
                        <option <?= shipmentFormValue($oldInput, $prefix . '[identification_type]', $defaultId) === $idType ? 'selected' : '' ?>><?= htmlspecialchars($idType, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-id-number">Identification Number<?= $shipper ? ' <span>*</span>' : '' ?></label>
                <input id="<?= $prefix ?>-id-number" name="<?= $prefix ?>[identification_number]" value="<?= $field('identification_number') ?>" <?= $shipper ? 'required' : '' ?> maxlength="16" data-rule="id-number" data-id-type="<?= htmlspecialchars(shipmentFormValue($oldInput, $prefix . '[identification_type]', $defaultId), ENT_QUOTES, 'UTF-8') ?>">
                <small class="shipment-required">Enter an identification number matching the selected type.</small>
            </div>
        </div>
        <div class="shipment-buttons">
            <button type="button" class="shipment-btn" data-save-address="<?= $prefix ?>">Save To Address Book</button>
            <button type="button" class="shipment-btn secondary" data-clear-address="<?= $prefix ?>">Clear Address</button>
        </div>
        <label class="shipment-check shipment-toggle-row">
            <input type="checkbox" data-auto-save="<?= $prefix ?>" data-auto-save-default="true" <?= shipmentOldChecked($oldInput, $prefix . '[auto_save]', true) ? 'checked' : '' ?>>
            Save Address Book Automatically
        </label>
        <input type="hidden" name="<?= $prefix ?>[auto_save]" value="<?= shipmentOldChecked($oldInput, $prefix . '[auto_save]', true) ? '1' : '0' ?>">
    </section>
    <?php
}
?>
<?php
$bodyAttributes = 'class="shipment-page shipment-app-page"';
require_once __DIR__ . '/includes/header.php';
?>
<div class="shipment-shell">
    <header class="shipment-header">
        <div>
            <h1>Create International Shipment</h1>
            <p class="shipment-muted">Ship from India to an overseas destination and create a trackable international shipment.</p>
        </div>
        <nav class="shipment-buttons">
            <a class="btn btn-secondary" href="<?= $role === 'admin' ? 'dashboard.php' : 'customer-dashboard.php' ?>">Dashboard</a>
            <a class="btn btn-secondary" href="shipment-list.php">Shipment List</a>
        </nav>
    </header>
    <?php if ($oldShipmentError !== ''): ?>
        <div class="shipment-error" role="alert"><?= $h($oldShipmentError) ?></div>
    <?php endif; ?>
    <?php if (!$shipmentSchemaReady): ?>
        <div class="shipment-error">Shipment tables are not set up yet. Import <code>database_shipments.sql</code> into the parcel_delivery_system database, then reload this page.</div>
    <?php endif; ?>
    <div id="shipment-toast" class="shipment-toast" role="status" aria-live="polite" aria-atomic="true"></div>
    <form id="shipment-form" action="save-shipment.php" method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <div class="shipment-grid">
            <?php renderAddressCard('shipper', $countries, $idTypes, $currentUser, $oldInput, $locations); ?>
            <?php renderAddressCard('receiver', $countries, $idTypes, $currentUser, $oldInput, $locations); ?>
            <section class="shipment-card">
                <h2>Shipment Details</h2>
                <div class="shipment-fields">
                    <div class="shipment-field"><label for="package-type">Package Type <span>*</span></label><select id="package-type" name="package_type" required><option <?= $v('package_type', 'Non Docs') === 'Non Docs' ? 'selected' : '' ?>>Non Docs</option><option <?= $v('package_type') === 'Docs' ? 'selected' : '' ?>>Docs</option></select></div>
                    <div class="shipment-field"><label for="pieces">No Of Pcs <span>*</span></label><input id="pieces" name="pieces" type="text" inputmode="numeric" data-rule="numbers" maxlength="2" min="1" max="99" value="<?= $v('pieces', '1') ?>" required><small class="shipment-required">Enter a number from 1 to 99.</small></div>
                    <div class="shipment-field"><label for="carrier">Carrier <span>*</span></label><select id="carrier" name="carrier" required><option>Self</option><option>Other</option></select></div>
                    <div class="shipment-field"><label for="service">Service <span>*</span></label><select id="service" name="service" required><option>Express Saver</option><option>Standard</option><option>Economy</option></select></div>
                    <div class="shipment-field"><label for="manual-invoice">Manual Invoice No</label><input id="manual-invoice" name="manual_invoice_no" value="<?= $v('manual_invoice_no') ?>" maxlength="30" data-rule="invoice-ref"><small class="shipment-required">Use letters, numbers, spaces, dots, underscores, slashes, #, parentheses, or hyphens.</small></div>
                    <div class="shipment-field"><label for="reference-number">Reference Number</label><input id="reference-number" name="reference_number" value="<?= $v('reference_number') ?>" maxlength="30" data-rule="alnum"><small class="shipment-required">Use letters, numbers, and spaces only.</small></div>
                    <div class="shipment-field"><label for="currency">Currency <span>*</span></label><select id="currency" name="currency" required><?php foreach (['INR', 'USD', 'EUR', 'GBP', 'AED'] as $currency): ?><option <?= $v('currency', 'INR') === $currency ? 'selected' : '' ?>><?= $h($currency) ?></option><?php endforeach; ?></select></div>
                    <div class="shipment-field"><label for="rate-fetch-type">Rate Fetch Type</label><select id="rate-fetch-type" name="rate_fetch_type"><option>Auto Contract Rate Plan</option><option>Manual</option></select></div>
                    <div class="shipment-field full"><label for="contents">Summarize Contents <span>*</span></label><div class="shipment-inline"><input id="contents" name="contents" value="<?= $v('contents') ?>" required maxlength="100" data-rule="alnum" placeholder="Briefly describe the shipment contents"><label class="shipment-check"><input type="hidden" name="contents_summarized" value="0"><input type="checkbox" name="contents_summarized" value="1" required <?= shipmentOldChecked($oldInput, 'contents_summarized') ? 'checked' : '' ?>> Summary confirmed</label></div><small class="shipment-required">Enter letters, numbers, and spaces (max 100).</small></div>
                    <div class="shipment-field full"><label for="remarks">Remarks</label><textarea id="remarks" name="remarks" maxlength="250"><?= $v('remarks') ?></textarea><small class="shipment-required">Maximum 250 characters.</small></div>
                    <div class="shipment-field"><label for="customer-reference">Customer Reference</label><select id="customer-reference" name="customer_reference"><option value="">Select</option><option>Business</option><option>Personal</option></select></div>
                    <div class="shipment-field"><label for="customs-status">Customs (Paid/UnPaid) <span>*</span></label><select id="customs-status" name="customs_status" required><option value="">Select customs payment status</option><option value="Paid" <?= $v('customs_status') === 'Paid' ? 'selected' : '' ?>>Paid</option><option value="UnPaid" <?= $v('customs_status') === 'UnPaid' ? 'selected' : '' ?>>UnPaid</option></select><small class="shipment-required">Select Paid or UnPaid.</small></div>
                </div>
                <div class="shipment-toggle-row">
                    <label class="shipment-check"><input type="hidden" name="tds" value="0"><input type="checkbox" name="tds" value="1" <?= shipmentOldChecked($oldInput, 'tds', true) ? 'checked' : '' ?>> TDS</label>
                    <label class="shipment-check"><input type="hidden" name="commercial" value="0"><input type="checkbox" name="commercial" value="1" <?= shipmentOldChecked($oldInput, 'commercial') ? 'checked' : '' ?>> Commercial</label>
                </div>
            </section>
        </div>

        <section class="shipment-section">
            <div class="shipment-section-heading"><h2>Package Details</h2></div>
            <div class="shipment-fields">
                <div class="shipment-field full"><label for="terms-of-delivery">Terms Of Delivery <span>*</span></label><select id="terms-of-delivery" name="terms_of_delivery" required><option>BEING SENDING FOR GIFT PURPOSE AND NOT FOR SALE</option><option>Commercial shipment</option><option>Personal effects</option></select></div>
                <div class="shipment-field"><label for="packaging">Packaging <span>*</span></label><select id="packaging" name="packaging" required><option>Use My Own Package</option><option>Box</option><option>Envelope</option><option>Pallet</option></select></div>
                <div class="shipment-field"><label for="package-pieces">Pcs</label><input id="package-pieces" type="text" inputmode="numeric" value="<?= $v('pieces', '1') ?>" readonly></div>
                <div class="shipment-field full">
                    <label>Dimensions and weight (repeat per piece)</label>
                    <div id="package-rows"></div>
                    <button id="copy-dimensions" class="shipment-btn secondary" type="button">Copy first piece dimensions to all pieces</button>
                </div>
                <input type="hidden" id="actual-wt-hidden" name="actual_wt" value="0">
                <input type="hidden" id="vol-wt-hidden" name="vol_wt" value="0">
                <input type="hidden" id="chg-wt-hidden" name="chg_wt" value="0">
            </div>
            <div class="shipment-total">
                <span>Total Pcs: <strong id="total-pcs">1</strong></span>
                <span>Total Actual Wt: <strong id="total-actual-wt">0.000</strong> KG</span>
                <span>Total Vol Wt: <strong id="total-vol-wt">0.000</strong> KG</span>
                <span>Total Chrg Wt: <strong id="total-chg-wt">0.000</strong> KG</span>
            </div>
        </section>

        <details class="shipment-section shipment-collapsible">
            <summary><strong>Click here to enter performa details</strong></summary>
            <div class="shipment-buttons"><button id="manifest-gst" type="button" class="shipment-btn secondary">Manifest GST Detail</button></div>
            <div class="shipment-fields" style="margin-top:16px">
                <div class="shipment-field"><label for="term-of-invoice">Term Of Invoice <span>*</span></label><select id="term-of-invoice" name="term_of_invoice" required><option value="DDU">DDU - Delivery duty unpaid</option><option value="DDP">DDP - Delivery duty paid</option><option value="DAP">DAP - Delivered at place</option></select></div>
                <div class="shipment-field"><label for="export-reason">Export Reason <span>*</span></label><select id="export-reason" name="export_reason" required><option>Gifts</option><option>Sample</option><option>Commercial</option><option>Personal</option><option>Return</option></select></div>
                <div class="shipment-field"><label for="gst-invoice">GST Invoice</label><select id="gst-invoice" name="gst_invoice"><option value="0">No</option><option value="1">Yes</option></select></div>
                <div class="shipment-field full" id="gst-summary" hidden><span>GSTIN: <strong id="gstin-summary"></strong></span><button type="button" class="shipment-btn secondary" data-open-gst>Enter GST details</button></div>
            </div>
        </details>

        <details class="shipment-section shipment-collapsible" open>
            <summary><strong>Click here to enter Commodity details</strong></summary>
            <div class="shipment-fields" style="margin-top:16px">
                <div class="shipment-field full">
                    <label for="commodity-select">Commodity List <span>*</span></label>
                    <div class="shipment-inline">
                        <input id="commodity-select" class="shipment-input" list="commodity-options" maxlength="100" data-rule="commodity" placeholder="Search or type a commodity">
                        <datalist id="commodity-options">
                            <?php foreach ($commodityCatalog as $item): ?>
                                <option value="<?= $h($item['name']) ?>" data-hs="<?= $h($item['hs']) ?>" data-price="<?= $h($item['price']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <button id="add-commodity" type="button" class="shipment-btn" aria-label="Add commodity">+</button>
                        <button id="refresh-commodities" type="button" class="shipment-btn secondary" aria-label="Reload commodity list">↻</button>
                    </div>
                    <small id="commodity-required" class="shipment-required" hidden>At least one commodity is required.</small>
                </div>
                <div class="shipment-field"><label for="commodity-currency">Currency</label><select id="commodity-currency" class="shipment-select" disabled><?php foreach (['INR', 'USD', 'EUR', 'GBP', 'AED'] as $currency): ?><option><?= $h($currency) ?></option><?php endforeach; ?></select></div>
                <div class="shipment-field full"><div id="commodity-rows"></div></div>
                <div class="shipment-field full">Total commodity value: <strong><span id="commodity-total">0.00</span> INR</strong><input type="hidden" id="total-value-hidden" name="total_value" value="0"></div>
                <div class="shipment-field"><label class="shipment-check"><input type="checkbox" id="own-invoice-toggle" name="own_invoice_enabled" value="1" <?= shipmentOldChecked($oldInput, 'own_invoice_enabled') ? 'checked' : '' ?>> Upload My Own Invoice (PDF)</label><input type="file" id="own-invoice" name="own_invoice" accept=".pdf,application/pdf" hidden><small id="invoice-error" class="shipment-required"></small></div>
                <div class="shipment-field"><label for="preview-type">Preview Invoice</label><select id="preview-type"><option>1-InvoicePrint</option></select><button id="preview-invoice" type="submit" name="preview_draft" value="1" formaction="invoice-preview.php" formtarget="_blank" class="shipment-btn secondary" disabled>Preview Invoice</button></div>
            </div>
        </details>

        <section class="shipment-section">
            <div class="shipment-section-heading"><h2>Additional Services</h2></div>
            <div class="shipment-toggle-row">
                <strong>How to Ship?</strong>
                <label class="shipment-radio"><input type="radio" name="ship_method" value="Schedule a Pick Up"> Schedule a Pick Up</label>
                <label class="shipment-radio"><input type="radio" name="ship_method" value="Drop Off at Carrier" checked> Drop Off at Carrier</label>
            </div>
            <div id="pickup-fields" class="shipment-fields" style="margin-top:14px" hidden>
                <div class="shipment-field"><label for="pickup-date">Pickup Date <span>*</span></label><input type="date" id="pickup-date" name="pickup_date"><small class="shipment-required">Select a date within the next 30 days.</small></div>
                <div class="shipment-field"><label for="pickup-time">Pickup Time Slot <span>*</span></label><select id="pickup-time" name="pickup_time"><option value="">Select a time slot</option><option value="09:00-12:00">09:00-12:00</option><option value="12:00-15:00">12:00-15:00</option><option value="15:00-18:00">15:00-18:00</option></select><small class="shipment-required">Select a pickup time slot.</small></div>
                <div class="shipment-field"><label for="pickup-contact-name">Pickup Contact Name <span>*</span></label><input id="pickup-contact-name" name="pickup_contact_name" value="<?= $v('pickup_contact_name', shipmentFormValue($oldInput, 'shipper[name]', (string) ($currentUser['full_name'] ?? ''))) ?>" maxlength="50" minlength="2" data-rule="letters"><small class="shipment-required">Enter 2–50 valid letters.</small></div>
                <div class="shipment-field"><label for="pickup-contact-phone">Pickup Contact Phone <span>*</span></label><input id="pickup-contact-phone" name="pickup_contact_phone" value="<?= $v('pickup_contact_phone', shipmentFormValue($oldInput, 'shipper[mobile]', (string) ($currentUser['phone'] ?? ''))) ?>" maxlength="10" inputmode="numeric" data-rule="numbers"><small class="shipment-required">Enter exactly 10 digits.</small></div>
                <div class="shipment-field full"><label for="pickup-instructions">Pickup Instructions</label><textarea id="pickup-instructions" name="pickup_instructions" maxlength="200"><?= $v('pickup_instructions') ?></textarea></div>
            </div>
        </section>
        <div class="shipment-actions">
            <button type="reset" class="shipment-btn secondary">Reset Form</button>
            <button type="submit" class="shipment-btn" <?= $shipmentSchemaReady ? '' : 'disabled' ?>>Submit Shipment</button>
        </div>
    </form>
</div>

<dialog id="address-dialog" class="shipment-dialog">
    <form method="dialog" class="shipment-fields">
        <div class="shipment-field full"><h2>Saved addresses</h2><select id="address-picker" class="shipment-select"></select></div>
        <div class="shipment-buttons"><button type="button" id="use-saved-address" class="shipment-btn">Use address</button><button type="button" id="delete-saved-address" class="shipment-btn danger">Delete address</button><button class="shipment-btn secondary">Close</button></div>
    </form>
</dialog>
<dialog id="gst-dialog" class="shipment-dialog">
    <form method="dialog" class="shipment-fields">
        <h2 class="shipment-full">Manifest GST details</h2>
        <label class="shipment-field">GSTIN<input id="gstin" name="gstin" form="shipment-form" maxlength="15" data-rule="gstin"><small class="shipment-required">Enter a valid 15-character GSTIN.</small></label>
        <label class="shipment-field">GST Legal Name<input name="gst_legal_name" form="shipment-form" maxlength="80" data-rule="alnum"><small class="shipment-required">Use letters, numbers, and spaces only (max 80).</small></label>
        <label class="shipment-field">GST Amount<input name="gst_amount" form="shipment-form" maxlength="6" inputmode="decimal" data-rule="decimal"><small class="shipment-required">Enter a value from 0 to 100.</small></label>
        <label class="shipment-field">IGST %<input name="gst_igst" form="shipment-form" maxlength="6" inputmode="decimal" data-rule="decimal"><small class="shipment-required">Enter a value from 0 to 100.</small></label>
        <div class="shipment-buttons shipment-full"><button class="shipment-btn">Done</button></div>
    </form>
</dialog>
<script>
window.shipmentOldInput = <?= json_encode($oldInput, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
window.shipmentOldPackages = <?= json_encode(is_array($oldInput['packages'] ?? null) ? array_values($oldInput['packages']) : [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
window.shipmentOldCommodities = <?= json_encode(is_array($oldInput['commodities'] ?? null) ? array_values($oldInput['commodities']) : [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
window.shipmentLocations = <?= json_encode($locations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
document.getElementById('pieces').addEventListener('change', function () {
    document.getElementById('package-pieces').value = this.value;
    document.getElementById('total-pcs').textContent = this.value;
});
document.getElementById('own-invoice-toggle').addEventListener('change', function () {
    const file = document.getElementById('own-invoice');
    file.hidden = !this.checked;
    file.required = this.checked;
    document.getElementById('preview-invoice').hidden = this.checked;
    document.getElementById('preview-type').disabled = this.checked;
});
</script>
<script src="assets/js/shipment.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/shipment.js') ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
