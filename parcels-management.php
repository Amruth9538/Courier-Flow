<?php
require_once __DIR__ . '/config.php';
require_auth();

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'customer'], true)) {
    http_response_code(403);
    exit('Only customers and administrators can book a domestic parcel.');
}

$ratesReady = (bool) $pdo->query("SHOW TABLES LIKE 'domestic_rates'")->fetchColumn();
$rates = [];
if ($ratesReady) {
    $rates = $pdo->query(
        'SELECT service, min_wt, max_wt, rate FROM domestic_rates ORDER BY service, min_wt'
    )->fetchAll();
}

$old = $_SESSION['parcel_old_input'] ?? [];
$error = $_SESSION['parcel_error'] ?? '';
unset($_SESSION['parcel_old_input'], $_SESSION['parcel_error']);
if (!is_array($old)) {
    $old = [];
}

$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$field = static function (string $group, string $key, string $default = '') use ($old, $h): string {
    $value = $old[$group][$key] ?? $default;
    return $h(is_scalar($value) ? $value : $default);
};
$parcelValue = static function (string $key, string $default = '') use ($old, $h): string {
    $value = $old[$key] ?? $default;
    return $h(is_scalar($value) ? $value : $default);
};

$userStmt = $pdo->prepare('SELECT full_name, email, phone FROM users WHERE id = ?');
$userStmt->execute([(int) $_SESSION['user_id']]);
$currentUser = $userStmt->fetch() ?: [];
$bodyAttributes = 'class="shipment-page shipment-app-page"';
require_once __DIR__ . '/includes/header.php';
?>
<div class="shipment-shell" id="parcel-booking">
    <header class="shipment-header">
        <div>
            <h1>Parcels Management</h1>
            <p class="shipment-muted">Book a domestic parcel. Sender and receiver must both be in India.</p>
        </div>
        <nav class="shipment-buttons">
            <a class="shipment-btn secondary" href="<?= $role === 'admin' ? 'dashboard.php' : 'customer-dashboard.php' ?>">Dashboard</a>
            <a class="shipment-btn secondary" href="parcel-list.php">Domestic Parcels</a>
        </nav>
    </header>

    <?php if ($error !== ''): ?>
        <div class="shipment-error" role="alert"><?= $h($error) ?></div>
    <?php endif; ?>

    <?php if (!$ratesReady): ?>
        <div class="shipment-error" role="alert">Domestic rates are not set up. Import <code>database_split.sql</code> before booking.</div>
    <?php endif; ?>

    <form id="parcel-form" action="save-parcel.php" method="post" novalidate>
        <?= csrf_field() ?>
        <div class="shipment-grid parcel-address-grid">
            <?php foreach (['sender' => 'Sender', 'receiver' => 'Receiver'] as $group => $title): ?>
                <section class="shipment-card">
                    <h2><?= $h($title) ?> Details</h2>
                    <div class="shipment-fields">
                        <div class="shipment-field">
                            <label for="<?= $group ?>-name"><?= $h($title) ?> Name <span>*</span></label>
                            <input id="<?= $group ?>-name" name="<?= $group ?>[name]" value="<?= $field($group, 'name', $group === 'sender' ? (string) ($currentUser['full_name'] ?? '') : '') ?>" required minlength="2" maxlength="50" data-rule="letters" autocomplete="name">
                            <small class="shipment-required" data-error-for="<?= $group ?>-name">Enter 2–50 letters, spaces, dots, hyphens, or apostrophes.</small>
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-country">Country <span>*</span></label>
                            <input id="<?= $group ?>-country" value="INDIA" readonly aria-readonly="true">
                            <input type="hidden" name="<?= $group ?>[country]" value="INDIA">
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-postal">Pincode <span>*</span></label>
                            <input id="<?= $group ?>-postal" name="<?= $group ?>[postal_code]" value="<?= $field($group, 'postal_code') ?>" required maxlength="6" inputmode="numeric" data-rule="numbers" data-pincode-lookup autocomplete="postal-code">
                            <small class="shipment-required" data-error-for="<?= $group ?>-postal">Enter a valid 6-digit Indian pincode.</small>
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-state">State <span>*</span></label>
                            <input id="<?= $group ?>-state" name="<?= $group ?>[state]" value="<?= $field($group, 'state') ?>" required maxlength="80" data-rule="letters">
                            <small class="shipment-required">Enter the state using letters only.</small>
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-city">City <span>*</span></label>
                            <input id="<?= $group ?>-city" name="<?= $group ?>[city]" value="<?= $field($group, 'city') ?>" required maxlength="80" data-rule="letters">
                            <small class="shipment-required">Enter the city using letters only.</small>
                        </div>
                        <div class="shipment-field full">
                            <label for="<?= $group ?>-address1">Address Line 1 <span>*</span></label>
                            <input id="<?= $group ?>-address1" name="<?= $group ?>[address_line1]" value="<?= $field($group, 'address_line1') ?>" required maxlength="150" data-rule="address">
                            <small class="shipment-required">Enter a valid address (max 150 characters).</small>
                        </div>
                        <div class="shipment-field full">
                            <label for="<?= $group ?>-address2">Address Line 2 <span>*</span></label>
                            <input id="<?= $group ?>-address2" name="<?= $group ?>[address_line2]" value="<?= $field($group, 'address_line2') ?>" required maxlength="150" data-rule="address">
                            <small class="shipment-required">Enter a valid address (max 150 characters).</small>
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-address3">Address Line 3</label>
                            <input id="<?= $group ?>-address3" name="<?= $group ?>[address_line3]" value="<?= $field($group, 'address_line3') ?>" maxlength="150" data-rule="address">
                            <small class="shipment-required">Use letters, numbers, spaces, and common address punctuation.</small>
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-landmark">Landmark</label>
                            <input id="<?= $group ?>-landmark" name="<?= $group ?>[landmark]" value="<?= $field($group, 'landmark') ?>" maxlength="120" data-rule="address">
                            <small class="shipment-required">Use letters, numbers, spaces, and common address punctuation.</small>
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-email"><?= $h($title) ?> Email</label>
                            <input id="<?= $group ?>-email" name="<?= $group ?>[email]" type="email" value="<?= $field($group, 'email', $group === 'sender' ? (string) ($currentUser['email'] ?? '') : '') ?>" maxlength="150" autocomplete="email">
                            <small class="shipment-required">Enter a valid email address.</small>
                        </div>
                        <div class="shipment-field">
                            <label for="<?= $group ?>-mobile">Mobile <span>*</span></label>
                            <div class="shipment-inline">
                                <input class="shipment-input" value="+91" readonly aria-label="Country calling code">
                                <input id="<?= $group ?>-mobile" name="<?= $group ?>[mobile]" value="<?= $field($group, 'mobile', $group === 'sender' ? (string) ($currentUser['phone'] ?? '') : '') ?>" required maxlength="10" inputmode="numeric" data-rule="numbers" data-indian-mobile autocomplete="tel-national">
                            </div>
                            <small class="shipment-required">Enter 10 digits; the first digit must be 6, 7, 8, or 9.</small>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <section class="shipment-section">
            <div class="shipment-section-heading"><h2>Parcel Details</h2></div>
            <div class="shipment-fields">
                <div class="shipment-field">
                    <label for="parcel-type">Parcel Type <span>*</span></label>
                    <select id="parcel-type" name="package_type" required>
                        <option value="Document" <?= $parcelValue('package_type', 'Non-Document') === 'Document' ? 'selected' : '' ?>>Document</option>
                        <option value="Non-Document" <?= $parcelValue('package_type', 'Non-Document') === 'Non-Document' ? 'selected' : '' ?>>Non-Document</option>
                    </select>
                </div>
                <div class="shipment-field">
                    <label for="parcel-pieces">No Of Pcs <span>*</span></label>
                    <input id="parcel-pieces" name="pieces" value="<?= $parcelValue('pieces', '1') ?>" required maxlength="2" inputmode="numeric" data-rule="numbers">
                    <small class="shipment-required">Enter a number from 1 to 99.</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-content">Content Description <span>*</span></label>
                    <input id="parcel-content" name="contents" value="<?= $parcelValue('contents') ?>" required maxlength="100" data-rule="alnum">
                    <small class="shipment-required">Use letters, numbers, and spaces (max 100 characters).</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-value">Declared Value (INR) <span>*</span></label>
                    <input id="parcel-value" name="declared_value" value="<?= $parcelValue('declared_value') ?>" required maxlength="12" inputmode="decimal" data-rule="decimal">
                    <small class="shipment-required">Enter a positive amount with up to 2 decimal places.</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-weight">Actual Wt (kg) <span>*</span></label>
                    <input id="parcel-weight" name="actual_wt" value="<?= $parcelValue('actual_wt') ?>" required maxlength="8" inputmode="decimal" data-rule="decimal">
                    <small class="shipment-required">Enter a weight greater than zero.</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-length">L (cm) <span>*</span></label>
                    <input id="parcel-length" name="length" value="<?= $parcelValue('length') ?>" required maxlength="8" inputmode="decimal" data-rule="decimal">
                    <small class="shipment-required">Enter a dimension greater than zero.</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-width">W (cm) <span>*</span></label>
                    <input id="parcel-width" name="width" value="<?= $parcelValue('width') ?>" required maxlength="8" inputmode="decimal" data-rule="decimal">
                    <small class="shipment-required">Enter a dimension greater than zero.</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-height">H (cm) <span>*</span></label>
                    <input id="parcel-height" name="height" value="<?= $parcelValue('height') ?>" required maxlength="8" inputmode="decimal" data-rule="decimal">
                    <small class="shipment-required">Enter a dimension greater than zero.</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-vol-wt">Vol Wt (kg)</label>
                    <input id="parcel-vol-wt" value="" readonly aria-readonly="true" data-vol-weight>
                </div>
                <div class="shipment-field">
                    <label for="parcel-chg-wt">Chg Wt (kg)</label>
                    <input id="parcel-chg-wt" value="" readonly aria-readonly="true" data-charge-weight>
                </div>
                <div class="shipment-field">
                    <label for="parcel-service">Service <span>*</span></label>
                    <select id="parcel-service" name="service_type" required data-rate-service>
                        <?php foreach (['Surface', 'Air', 'Express'] as $service): ?>
                            <option value="<?= $h($service) ?>" <?= $parcelValue('service_type', 'Surface') === $service ? 'selected' : '' ?>><?= $h($service) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="shipment-field">
                    <label for="payment-mode">Payment Mode <span>*</span></label>
                    <select id="payment-mode" name="payment_mode" required data-payment-mode>
                        <?php foreach (['Prepaid', 'COD', 'To-Pay'] as $mode): ?>
                            <option value="<?= $h($mode) ?>" <?= $parcelValue('payment_mode', 'Prepaid') === $mode ? 'selected' : '' ?>><?= $h($mode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="shipment-field" data-cod-field>
                    <label for="cod-amount">COD Amount (INR) <span>*</span></label>
                    <input id="cod-amount" name="cod_amount" value="<?= $parcelValue('cod_amount') ?>" maxlength="12" inputmode="decimal" data-rule="decimal">
                    <small class="shipment-required">Enter a positive COD amount when payment mode is COD.</small>
                </div>
                <div class="shipment-field full">
                    <label for="delivery-instructions">Delivery Instructions</label>
                    <textarea id="delivery-instructions" name="delivery_instructions" maxlength="200"><?= $parcelValue('delivery_instructions') ?></textarea>
                    <small class="shipment-required">Maximum 200 characters.</small>
                </div>
                <div class="shipment-field full">
                    <label for="parcel-remarks">Remarks</label>
                    <textarea id="parcel-remarks" name="remarks" maxlength="250"><?= $parcelValue('remarks') ?></textarea>
                    <small class="shipment-required">Maximum 250 characters.</small>
                </div>
                <div class="shipment-field">
                    <label for="parcel-gstin">Business GSTIN (optional)</label>
                    <input id="parcel-gstin" name="gstin" value="<?= $parcelValue('gstin') ?>" maxlength="15" data-rule="gstin" autocomplete="off">
                    <small class="shipment-required">Enter a valid 15-character GSTIN.</small>
                </div>
                <div class="shipment-field full">
                    <p class="shipment-note">Declared value and rates are in INR. GST is optional for business parcels.</p>
                </div>
            </div>

            <div class="shipment-total">
                <span>Domestic rate estimate:</span>
                <strong id="domestic-rate-estimate" aria-live="polite">Enter parcel weight and select a service.</strong>
            </div>
            <p class="shipment-note">Rate estimates use the highest of actual and volumetric weight and the matching configured weight slab.</p>

            <div class="shipment-table-wrap" style="margin-top:12px">
                <table class="shipment-table">
                    <thead><tr><th>Service</th><th>Weight slab (kg)</th><th>Rate (INR)</th></tr></thead>
                    <tbody>
                    <?php if (!$rates): ?><tr><td colspan="3">No rate slabs configured yet.</td></tr><?php endif; ?>
                    <?php foreach ($rates as $rate): ?>
                        <tr>
                            <td><?= $h($rate['service']) ?></td>
                            <td><?= number_format((float) $rate['min_wt'], 3) ?>–<?= number_format((float) $rate['max_wt'], 3) ?></td>
                            <td>₹<?= number_format((float) $rate['rate'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="shipment-actions">
            <button class="shipment-btn secondary" type="reset">Reset Form</button>
            <button class="shipment-btn" type="submit" <?= !$ratesReady ? 'disabled' : '' ?>>Book Domestic Parcel</button>
        </div>
    </form>
</div>
<script>
window.domesticRateSlabs = <?= json_encode($rates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/js/parcel.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
