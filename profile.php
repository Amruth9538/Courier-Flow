<?php
require_once __DIR__ . '/config.php';
require_auth();

if (is_admin()) {
    redirect('dashboard.php');
}

$rider = ensure_rider_profile();
if (!$rider) {
    redirect('rider-dashboard.php');
}

$profile = $pdo->prepare('SELECT u.full_name, u.phone, r.* FROM users u LEFT JOIN riders r ON r.user_id = u.id WHERE u.id = ?');
$profile->execute([$_SESSION['user_id']]);
$profile = $profile->fetch();
if (!$profile) {
    redirect('rider-dashboard.php');
}

$rider = $profile;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // 1. 获取并过滤输入字段
    $full_name = sanitize_string($_POST['full_name'] ?? '');
    $phone     = sanitize_string($_POST['phone'] ?? '');
    $vehicle   = sanitize_string($_POST['vehicle_type'] ?? '');
    $license   = sanitize_string($_POST['license_number'] ?? '');

    // 2. 更新 users 表 (Full Name & Phone)
    $pdo->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')
        ->execute([$full_name, $phone, $_SESSION['user_id']]);

    // 3. 更新 riders 表 (Vehicle Type & License Number)
    $pdo->prepare('UPDATE riders SET vehicle_type = ?, license_number = ? WHERE id = ?')
        ->execute([$vehicle, $license, $rider['id']]);

    $message = 'Profile updated.';

    // 4. 重新获取最新的 Profile 数据
    $profile = $pdo->prepare('SELECT u.full_name, u.phone, r.* FROM users u LEFT JOIN riders r ON r.user_id = u.id WHERE u.id = ?');
    $profile->execute([$_SESSION['user_id']]);
    $rider = $profile->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="profile-page">
    <section class="panel profile-card" aria-labelledby="profile-title">
        <header class="profile-card__header">
            <div class="profile-card__identity">
                <span class="profile-card__avatar" aria-hidden="true">
                    <i class="bi bi-person-vcard"></i>
                </span>
                <div>
                    <p class="profile-card__eyebrow">Rider account</p>
                    <h2 id="profile-title">Profile settings</h2>
                    <p class="profile-card__description">Keep your contact and vehicle details up to date.</p>
                </div>
            </div>
            <span class="profile-card__badge">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                Account details
            </span>
        </header>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success profile-success" role="status">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" class="profile-form">
            <?= csrf_field() ?>

            <section class="profile-section" aria-labelledby="personal-details-title">
                <div class="profile-section__heading">
                    <span class="profile-section__icon" aria-hidden="true"><i class="bi bi-person"></i></span>
                    <div>
                        <h3 id="personal-details-title">Personal information</h3>
                        <p>Your name and the phone number used to contact you.</p>
                    </div>
                </div>

                <div class="form-grid profile-fields">
                    <label class="profile-field">
                        <span>Full name <span class="profile-required" aria-hidden="true">*</span></span>
                        <input type="text" name="full_name" autocomplete="name" value="<?= htmlspecialchars($rider['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>

                    <label class="profile-field">
                        <span>Phone number <span class="profile-required" aria-hidden="true">*</span></span>
                        <input type="tel" name="phone" autocomplete="tel" inputmode="tel" value="<?= htmlspecialchars($rider['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    </label>
                </div>
            </section>

            <section class="profile-section" aria-labelledby="vehicle-details-title">
                <div class="profile-section__heading">
                    <span class="profile-section__icon" aria-hidden="true"><i class="bi bi-bicycle"></i></span>
                    <div>
                        <h3 id="vehicle-details-title">Vehicle information</h3>
                        <p>Details that help identify your delivery vehicle.</p>
                    </div>
                </div>

                <div class="form-grid profile-fields">
                    <label class="profile-field">
                        <span>Vehicle type</span>
                        <input type="text" name="vehicle_type" autocomplete="off" placeholder="e.g. Scooter, motorcycle" value="<?= htmlspecialchars($rider['vehicle_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </label>

                    <label class="profile-field">
                        <span>License number</span>
                        <input type="text" name="license_number" autocomplete="off" placeholder="Enter your license number" value="<?= htmlspecialchars($rider['license_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                </div>
            </section>

            <footer class="profile-form__footer">
                <p><span aria-hidden="true">*</span> Required fields</p>
                <button class="btn btn-primary profile-save-button" type="submit">
                    <i class="bi bi-check2" aria-hidden="true"></i>
                    Save changes
                </button>
            </footer>
        </form>
    </section>
</div>
<style>
.rider-app-shell > .sidebar {
    background: #101b2d !important;
    border: 0 !important;
    box-shadow: 4px 0 20px rgba(15, 23, 42, .06) !important;
}

.rider-app-shell .courier-brand h2 {
    color: #f8fafc !important;
}

.rider-app-shell .courier-brand h2 span {
    color: inherit !important;
}

.rider-app-shell .courier-brand h2 i {
    color: #60a5fa !important;
}

.rider-app-shell .courier-brand p {
    color: #91a0b7 !important;
}

.rider-app-shell .courier-nav .nav-link {
    color: #c4cede !important;
    background: transparent !important;
    border: 1px solid transparent;
    box-shadow: none !important;
}

.rider-app-shell .courier-nav .nav-link:hover,
.rider-app-shell .courier-nav .nav-link.active {
    color: #fff !important;
    background: #22314a !important;
}

.rider-app-shell .courier-nav .nav-link.active {
    border-color: rgba(96, 165, 250, .25);
    box-shadow: inset 3px 0 #60a5fa !important;
}

.profile-page {
    width: min(100%, 980px);
    margin: 0 auto;
}

.profile-card {
    padding: 0 !important;
    overflow: hidden;
    background: #fff !important;
    border: 1px solid #e6ebf2 !important;
    border-radius: 16px !important;
    box-shadow: 0 4px 16px rgba(15, 23, 42, .045) !important;
}

.profile-card__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 26px 30px;
    background: linear-gradient(110deg, #fff 0%, #f8fbff 100%);
    border-bottom: 1px solid #edf0f5;
}

.profile-card__identity {
    display: flex;
    align-items: center;
    gap: 16px;
}

.profile-card__avatar,
.profile-section__icon {
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    color: #2563eb;
    background: #edf4ff;
    border: 1px solid #e1ebfb;
}

.profile-card__avatar {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    font-size: 24px;
}

.profile-card__eyebrow {
    margin: 0 0 4px;
    color: #2563eb;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.profile-card__header h2 {
    margin: 0;
    color: #172b4d;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -.3px;
}

.profile-card__description {
    margin: 5px 0 0;
    color: #718096;
    font-size: 13px;
    line-height: 1.5;
}

.profile-card__badge {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 7px;
    padding: 8px 11px;
    color: #52657e;
    background: #fff;
    border: 1px solid #e4eaf2;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}

.profile-card__badge i {
    color: #16865d;
}

.profile-success {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 20px 30px 0 !important;
}

.profile-form {
    padding: 8px 30px 0;
}

.profile-section {
    padding: 22px 0 24px;
    border-bottom: 1px solid #edf0f5;
}

.profile-section__heading {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 18px;
}

.profile-section__icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    font-size: 16px;
}

.profile-section__heading h3 {
    margin: 0;
    color: #24364b;
    font-size: 14px;
    font-weight: 700;
}

.profile-section__heading p {
    margin: 4px 0 0;
    color: #7b8798;
    font-size: 12px;
    line-height: 1.5;
}

.profile-fields {
    gap: 18px 20px;
}

.profile-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
    color: #42526b;
    font-size: 12px;
    font-weight: 600;
}

.profile-required,
.profile-form__footer > p span {
    color: #d14343;
}

.profile-fields .profile-field input {
    min-height: 44px;
    padding: 10px 12px;
    color: #172b4d;
    background: #fff;
    border: 1px solid #d6deea;
    border-radius: 9px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.4;
    transition: border-color .16s ease, box-shadow .16s ease;
}

.profile-fields .profile-field input::placeholder {
    color: #a0aaba;
}

.profile-fields .profile-field input:hover {
    border-color: #aab8ca;
}

.profile-fields .profile-field input:focus {
    border-color: #4b83e6;
    outline: 0;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
}

.profile-form__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 0 24px;
}

.profile-form__footer > p {
    margin: 0;
    color: #7b8798;
    font-size: 12px;
}

.profile-save-button {
    display: inline-flex;
    min-height: 42px;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 17px;
    border-radius: 9px;
    font-size: 13px;
}

@media (max-width: 700px) {
    .profile-card__header {
        align-items: flex-start;
        padding: 22px 20px;
    }

    .profile-card__badge {
        display: none;
    }

    .profile-form {
        padding: 4px 20px 0;
    }

    .profile-section {
        padding: 20px 0;
    }

    .profile-fields {
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .profile-success {
        margin: 16px 20px 0 !important;
    }
}

@media (max-width: 420px) {
    .profile-card__avatar {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        font-size: 20px;
    }

    .profile-card__identity {
        gap: 12px;
    }

    .profile-card__header h2 {
        font-size: 19px;
    }

    .profile-card__description {
        font-size: 12px;
    }

    .profile-form__footer {
        align-items: stretch;
        flex-direction: column;
    }

    .profile-save-button {
        width: 100%;
    }
}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>