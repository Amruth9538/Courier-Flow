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
];
$idTypes = ['Aadhaar Number', 'PAN', 'Passport', 'Driving License', 'Voter ID'];
$commodityCatalog = [
    ['name' => 'Clothing', 'hs' => '6109', 'price' => '500'],
    ['name' => 'Books', 'hs' => '4901', 'price' => '250'],
    ['name' => 'Documents', 'hs' => '4911', 'price' => '100'],
    ['name' => 'Food products', 'hs' => '2106', 'price' => '350'],
    ['name' => 'Home decor', 'hs' => '3926', 'price' => '750'],
    ['name' => 'Personal effects', 'hs' => '9804', 'price' => '500'],
    ['name' => 'Toys', 'hs' => '9503', 'price' => '400'],
];
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

function renderAddressCard(string $type, array $countries, array $idTypes, array $currentUser): void
{
    $shipper = $type === 'shipper';
    $title = $shipper ? 'Shipper Details' : 'Receiver Details';
    $prefix = $type;
    $defaultCountry = $shipper ? 'INDIA' : 'UNITED STATES';
    $defaultCode = $shipper ? '+91' : '+1';
    $defaultId = $shipper ? 'Aadhaar Number' : 'Driving License';
    $name = $shipper ? ($currentUser['full_name'] ?? '') : '';
    $email = $shipper ? ($currentUser['email'] ?? '') : '';
    $phone = $shipper ? ($currentUser['phone'] ?? '') : '';
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
                <input id="<?= $prefix ?>-name" name="<?= $prefix ?>[name]" value="<?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?>" required maxlength="150">
                <small class="shipment-required">Required</small>
            </div>
            <label class="shipment-check shipment-full"><input type="checkbox" name="<?= $prefix ?>[is_default]" value="1"> Make Default Address</label>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-company">Company</label>
                <input id="<?= $prefix ?>-company" name="<?= $prefix ?>[company]" maxlength="150">
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-country">Country/Territory <span>*</span></label>
                <select id="<?= $prefix ?>-country" name="<?= $prefix ?>[country]" data-country-select required>
                    <?php foreach ($countries as $country => $display): ?>
                        <option value="<?= htmlspecialchars($country, ENT_QUOTES, 'UTF-8') ?>" data-code="<?= htmlspecialchars($display[1], ENT_QUOTES, 'UTF-8') ?>" <?= $country === $defaultCountry ? 'selected' : '' ?>><?= htmlspecialchars($display[0], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="shipment-required">Required</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-postal">Postal Code <span>*</span></label>
                <input id="<?= $prefix ?>-postal" name="<?= $prefix ?>[postal_code]" data-postal-lookup data-country="<?= htmlspecialchars($defaultCountry, ENT_QUOTES, 'UTF-8') ?>" required maxlength="20" inputmode="numeric">
                <small class="shipment-required">Required (India: 6 digits)</small>
                <small id="<?= $prefix ?>-postal-hint" class="shipment-note"></small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-state">State <span>*</span></label>
                <input id="<?= $prefix ?>-state" name="<?= $prefix ?>[state]" required maxlength="100">
                <small class="shipment-required">Required</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-state-code">State Code <span>*</span></label>
                <input id="<?= $prefix ?>-state-code" name="<?= $prefix ?>[state_code]" required maxlength="20" readonly>
                <small class="shipment-required">Required</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-city">City <span>*</span></label>
                <input id="<?= $prefix ?>-city" name="<?= $prefix ?>[city]" required maxlength="100">
                <small class="shipment-required">Required</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-station">Station Name</label>
                <input id="<?= $prefix ?>-station" name="<?= $prefix ?>[station_name]" maxlength="100" readonly>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-suburb">Sub Urb <span>*</span></label>
                <input id="<?= $prefix ?>-suburb" name="<?= $prefix ?>[sub_urb]" required maxlength="100">
                <small class="shipment-required">Required</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-short-address">Short Address</label>
                <input id="<?= $prefix ?>-short-address" name="<?= $prefix ?>[short_address]" maxlength="255">
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-latitude">Latitude</label>
                <input id="<?= $prefix ?>-latitude" name="<?= $prefix ?>[latitude]" type="number" step="0.0000001" value="0.00">
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-longitude">Longitude</label>
                <input id="<?= $prefix ?>-longitude" name="<?= $prefix ?>[longitude]" type="number" step="0.0000001" value="0.00">
            </div>
            <?php for ($line = 1; $line <= 3; $line++): ?>
                <div class="shipment-field <?= $line === 3 ? 'full' : '' ?>">
                    <label for="<?= $prefix ?>-line<?= $line ?>">Address Line <?= $line ?><?= $line < 3 ? ' <span>*</span>' : '' ?></label>
                    <input id="<?= $prefix ?>-line<?= $line ?>" name="<?= $prefix ?>[address_line<?= $line ?>]" <?= $line < 3 ? 'required' : '' ?> maxlength="255">
                    <?php if ($line < 3): ?><small class="shipment-required">Required</small><?php endif; ?>
                </div>
            <?php endfor; ?>
            <div class="shipment-field full">
                <label for="<?= $prefix ?>-email"><?= $shipper ? 'Sender' : 'Recipient' ?> Email</label>
                <input id="<?= $prefix ?>-email" name="<?= $prefix ?>[email]" type="email" maxlength="150" value="<?= htmlspecialchars((string) $email, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-phone-type">Phone Type</label>
                <select id="<?= $prefix ?>-phone-type" name="<?= $prefix ?>[phone_type]"><option>Mobile</option><option>Landline</option></select>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-country-code">Country Code</label>
                <select id="<?= $prefix ?>-country-code" name="<?= $prefix ?>[country_code]"><option><?= htmlspecialchars($defaultCode, ENT_QUOTES, 'UTF-8') ?></option><option>+91</option><option>+1</option><option>+44</option><option>+61</option></select>
            </div>
            <div class="shipment-field full">
                <label for="<?= $prefix ?>-mobile">Mobile/Cell No <span>*</span></label>
                <input id="<?= $prefix ?>-mobile" name="<?= $prefix ?>[mobile]" value="<?= htmlspecialchars((string) $phone, ENT_QUOTES, 'UTF-8') ?>" required maxlength="30" inputmode="tel">
                <small class="shipment-required">Required</small>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-id-type">Identification Type <span>*</span></label>
                <select id="<?= $prefix ?>-id-type" name="<?= $prefix ?>[identification_type]" required>
                    <?php foreach ($idTypes as $idType): ?>
                        <option <?= $idType === $defaultId ? 'selected' : '' ?>><?= htmlspecialchars($idType, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="shipment-field">
                <label for="<?= $prefix ?>-id-number">Identification Number <span>*</span></label>
                <input id="<?= $prefix ?>-id-number" name="<?= $prefix ?>[identification_number]" required maxlength="80">
                <small class="shipment-required">Required (Aadhaar: 12 digits)</small>
            </div>
        </div>
        <div class="shipment-buttons">
            <button type="button" class="shipment-btn" data-save-address="<?= $prefix ?>">Save To Address Book</button>
            <button type="button" class="shipment-btn secondary" data-clear-address="<?= $prefix ?>">Clear Address</button>
        </div>
        <label class="shipment-check shipment-toggle-row">
            <input type="checkbox" data-auto-save="<?= $prefix ?>" data-auto-save-default="true" checked>
            Save Address Book Automatically
        </label>
        <input type="hidden" name="<?= $prefix ?>[auto_save]" value="1">
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
            <h1>Create Shipment</h1>
            <p class="shipment-muted">Book a parcel and create a trackable shipment.</p>
        </div>
        <nav class="shipment-buttons">
            <a class="btn btn-secondary" href="<?= $role === 'admin' ? 'dashboard.php' : 'customer-dashboard.php' ?>">Dashboard</a>
            <a class="btn btn-secondary" href="shipment-list.php">Shipment List</a>
        </nav>
    </header>
    <?php if (isset($_GET['error'])): ?>
        <div class="shipment-error"><?= $h($_SESSION['shipment_error'] ?? 'Unable to save shipment. Check the form and try again.') ?></div>
        <?php unset($_SESSION['shipment_error']); ?>
    <?php endif; ?>
    <?php if (!$shipmentSchemaReady): ?>
        <div class="shipment-error">Shipment tables are not set up yet. Import <code>database_shipments.sql</code> into the parcel_delivery_system database, then reload this page.</div>
    <?php endif; ?>
    <div id="shipment-toast" class="shipment-toast" role="status" aria-live="polite"></div>
    <form id="shipment-form" action="save-shipment.php" method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <div class="shipment-grid">
            <?php renderAddressCard('shipper', $countries, $idTypes, $currentUser); ?>
            <?php renderAddressCard('receiver', $countries, $idTypes, $currentUser); ?>
            <section class="shipment-card">
                <h2>Shipment Details</h2>
                <div class="shipment-fields">
                    <div class="shipment-field"><label for="package-type">Package Type <span>*</span></label><select id="package-type" name="package_type" required><option>Non Docs</option><option>Docs</option></select></div>
                    <div class="shipment-field"><label for="pieces">No Of Pcs <span>*</span></label><input id="pieces" name="pieces" type="number" min="1" max="50" value="1" required></div>
                    <div class="shipment-field"><label for="carrier">Carrier <span>*</span></label><select id="carrier" name="carrier" required><option>Self</option><option>CourierFlow</option><option>Other</option></select></div>
                    <div class="shipment-field"><label for="service">Service <span>*</span></label><select id="service" name="service" required><option>Express Saver</option><option>Standard</option><option>Economy</option></select></div>
                    <div class="shipment-field"><label for="manual-invoice">Manual Invoice No</label><input id="manual-invoice" name="manual_invoice_no" maxlength="80"></div>
                    <div class="shipment-field"><label for="reference-number">Reference Number</label><input id="reference-number" name="reference_number" maxlength="100"></div>
                    <div class="shipment-field"><label for="currency">Currency</label><select id="currency" name="currency"><option>INR</option><option>USD</option><option>EUR</option><option>GBP</option></select></div>
                    <div class="shipment-field"><label for="rate-fetch-type">Rate Fetch Type</label><select id="rate-fetch-type" name="rate_fetch_type"><option>Auto Contract Rate Plan</option><option>Manual</option></select></div>
                    <div class="shipment-field full"><label for="contents">Summarize Contents <span>*</span></label><div class="shipment-inline"><input id="contents" name="contents" required maxlength="255" placeholder="Briefly describe the shipment contents"><label class="shipment-check"><input type="checkbox" name="contents_summarized" value="1" required> Summary confirmed</label></div><small class="shipment-required">Required</small></div>
                    <div class="shipment-field full"><label for="remarks">Remarks</label><textarea id="remarks" name="remarks"></textarea></div>
                    <div class="shipment-field"><label for="customer-reference">Customer Reference</label><select id="customer-reference" name="customer_reference"><option value="">Select</option><option>Website</option><option>Business</option><option>Personal</option></select></div>
                    <div class="shipment-field"><label for="customs-status">Customs (Paid/UnPaid)</label><textarea id="customs-status" name="customs_status" rows="2" placeholder="Enter customs payment or handling notes"></textarea></div>
                </div>
                <div class="shipment-toggle-row">
                    <label class="shipment-check"><input type="checkbox" name="tds" value="1"> TDS</label>
                    <label class="shipment-check"><input type="checkbox" name="commercial" value="1"> Commercial</label>
                </div>
            </section>
        </div>

        <section class="shipment-section">
            <div class="shipment-section-heading"><h2>Package Details</h2></div>
            <div class="shipment-fields">
                <div class="shipment-field full"><label for="terms-of-delivery">Terms Of Delivery</label><select id="terms-of-delivery" name="terms_of_delivery"><option>BEING SENDING FOR GIFT PURPOSE AND NOT FOR SALE</option><option>Commercial shipment</option><option>Personal effects</option></select></div>
                <div class="shipment-field"><label for="packaging">Packaging</label><select id="packaging" name="packaging"><option>Use My Own Package</option><option>CourierFlow Packaging</option></select></div>
                <div class="shipment-field"><label for="package-pieces">Pcs</label><input id="package-pieces" type="number" value="1" readonly></div>
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
                <div class="shipment-field"><label>CSB Type</label><select name="csb_type"><option>CSB 4</option><option>CSB 5</option><option>CSB 3</option></select></div>
                <div class="shipment-field"><label>Term Of Invoice</label><select name="term_of_invoice"><option>DDU - Delivery duty unpaid</option><option>DDP - Delivery duty paid</option><option>DAP - Delivered at place</option></select></div>
                <div class="shipment-field"><label>Export Reason</label><select name="export_reason"><option>Gifts</option><option>Sale</option><option>Personal Effects</option><option>Documents</option></select></div>
                <div class="shipment-field"><label>GST Invoice</label><select name="gst_invoice"><option value="0">No</option><option value="1">Yes</option></select></div>
            </div>
        </details>

        <details class="shipment-section shipment-collapsible" open>
            <summary><strong>Click here to enter Commodity details</strong></summary>
            <div class="shipment-fields" style="margin-top:16px">
                <div class="shipment-field full">
                    <label for="commodity-select">Commodity List <span>*</span></label>
                    <div class="shipment-inline">
                        <input id="commodity-select" class="shipment-input" list="commodity-options" placeholder="Search or type a commodity">
                        <datalist id="commodity-options">
                            <?php foreach ($commodityCatalog as $item): ?>
                                <option value="<?= $h($item['name']) ?>" data-hs="<?= $h($item['hs']) ?>" data-price="<?= $h($item['price']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <button id="add-commodity" type="button" class="shipment-btn" aria-label="Add commodity">+</button>
                        <button id="refresh-commodities" type="button" class="shipment-btn secondary" aria-label="Clear commodity selection">↻</button>
                    </div>
                    <small id="commodity-required" class="shipment-required" hidden>At least one commodity is required.</small>
                </div>
                <div class="shipment-field"><label for="commodity-currency">Currency</label><select id="commodity-currency" class="shipment-select" disabled><option>INR</option></select></div>
                <div class="shipment-field full"><div id="commodity-rows"></div></div>
                <div class="shipment-field full">Total commodity value: <strong><span id="commodity-total">0.00</span> INR</strong><input type="hidden" id="total-value-hidden" name="total_value" value="0"></div>
                <div class="shipment-field"><label class="shipment-check"><input type="checkbox" id="own-invoice-toggle"> Upload My Own Invoice (PDF)</label><input type="file" id="own-invoice" name="own_invoice" accept="application/pdf" hidden></div>
                <div class="shipment-field"><label for="preview-type">Preview Invoice</label><select id="preview-type"><option>1-InvoicePrint</option></select><button id="preview-invoice" type="submit" name="preview_draft" value="1" formaction="invoice-preview.php" formtarget="_blank" class="shipment-btn secondary">Preview Invoice</button></div>
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
                <div class="shipment-field"><label for="pickup-date">Pickup Date <span>*</span></label><input type="date" id="pickup-date" name="pickup_date"></div>
                <div class="shipment-field"><label for="pickup-time">Pickup Time <span>*</span></label><input type="time" id="pickup-time" name="pickup_time"></div>
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
        <label class="shipment-field">GSTIN<input name="gstin" form="shipment-form" maxlength="30"></label>
        <label class="shipment-field">GST details<textarea name="gst_details" form="shipment-form"></textarea></label>
        <div class="shipment-buttons shipment-full"><button class="shipment-btn">Done</button></div>
    </form>
</dialog>
<script>
document.getElementById('pieces').addEventListener('change', function () {
    document.getElementById('package-pieces').value = this.value;
    document.getElementById('total-pcs').textContent = this.value;
});
document.getElementById('own-invoice-toggle').addEventListener('change', function () {
    document.getElementById('own-invoice').hidden = !this.checked;
});
</script>
<script src="assets/js/shipment.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
