<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

function tracking_badge_class(string $status): string
{
    return 'status-' . preg_replace('/[^a-z_]/', '', $status);
}

function account_flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['account_flash'] = ['message' => $message, 'type' => $type];
}

function account_flash_get(): ?array
{
    $flash = $_SESSION['account_flash'] ?? null;
    unset($_SESSION['account_flash']);
    return is_array($flash) ? $flash : null;
}

$profileReady = ensure_user_profile_schema();
$trackingReady = ensure_logistics_schema();
$deliveryChatReady = ensure_delivery_chat_schema();
$user = current_user();
$profileForm = [
    'full_name' => trim((string) ($user['full_name'] ?? '')),
    'username' => trim((string) ($user['username'] ?? '')),
    'phone' => trim((string) ($user['phone'] ?? '')),
    'birthdate' => trim((string) ($user['birthdate'] ?? '')),
    'gender' => trim((string) ($user['gender'] ?? '')),
    'address_line' => trim((string) ($user['address_line'] ?? '')),
    'city' => trim((string) ($user['city'] ?? '')),
    'province' => trim((string) ($user['province'] ?? '')),
    'zip_code' => trim((string) ($user['zip_code'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_profile') {
    $profileForm['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $profileForm['username'] = trim((string) ($_POST['username'] ?? ''));
    $profileForm['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $profileForm['birthdate'] = trim((string) ($_POST['birthdate'] ?? ''));
    $profileForm['gender'] = trim((string) ($_POST['gender'] ?? ''));
    $profileForm['address_line'] = trim((string) ($_POST['address_line'] ?? ''));
    $profileForm['city'] = trim((string) ($_POST['city'] ?? ''));
    $profileForm['province'] = trim((string) ($_POST['province'] ?? ''));
    $profileForm['zip_code'] = trim((string) ($_POST['zip_code'] ?? ''));

    $profileError = '';
    if (!$profileReady) {
        $profileError = 'User profile fields are not ready yet. Run the user profile migration and try again.';
    } elseif (
        $profileForm['full_name'] === '' ||
        $profileForm['username'] === '' ||
        $profileForm['phone'] === '' ||
        $profileForm['address_line'] === '' ||
        $profileForm['city'] === '' ||
        $profileForm['province'] === ''
    ) {
        $profileError = 'Please complete the required profile fields.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $profileForm['username'])) {
        $profileError = 'Username must be 3 to 30 characters and use only letters, numbers, dot, dash, or underscore.';
    } elseif (!is_valid_ph_mobile($profileForm['phone'])) {
        $profileError = 'Use a valid Philippine mobile number (09XXXXXXXXX).';
    } elseif ($profileForm['birthdate'] !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $profileForm['birthdate']);
        if (!($date instanceof DateTime) || $date->format('Y-m-d') !== $profileForm['birthdate']) {
            $profileError = 'Birthdate format is invalid.';
        }
    }

    if ($profileError === '') {
        $checkStmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $exists = false;
        if ($checkStmt instanceof mysqli_stmt) {
            $selfId = (int) ($_SESSION['user_id'] ?? 0);
            $checkStmt->bind_param('si', $profileForm['username'], $selfId);
            $checkStmt->execute();
            $exists = (bool) $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
        }
        if ($exists) {
            $profileError = 'Username is already taken.';
        }
    }

    if ($profileError !== '') {
        account_flash_set($profileError, 'error');
    } else {
        $normalizedPhone = normalize_ph_mobile($profileForm['phone']);
        $birthdateValue = $profileForm['birthdate'] !== '' ? $profileForm['birthdate'] : null;
        $updateStmt = db()->prepare('UPDATE users SET full_name = ?, username = ?, phone = ?, birthdate = ?, gender = ?, address_line = ?, city = ?, province = ?, zip_code = ? WHERE id = ?');
        if ($updateStmt instanceof mysqli_stmt) {
            $selfId = (int) ($_SESSION['user_id'] ?? 0);
            $updateStmt->bind_param(
                'sssssssssi',
                $profileForm['full_name'],
                $profileForm['username'],
                $normalizedPhone,
                $birthdateValue,
                $profileForm['gender'],
                $profileForm['address_line'],
                $profileForm['city'],
                $profileForm['province'],
                $profileForm['zip_code'],
                $selfId
            );
            $ok = $updateStmt->execute();
            $updateStmt->close();
            account_flash_set($ok ? 'Profile updated successfully.' : 'Unable to update profile right now.', $ok ? 'success' : 'error');
        } else {
            account_flash_set('Unable to prepare profile update.', 'error');
        }
    }

    header('Location: ' . BASE_URL . '/account.php');
    exit;
}

$user = current_user();
if ($user) {
    $profileForm['full_name'] = trim((string) ($user['full_name'] ?? ''));
    $profileForm['username'] = trim((string) ($user['username'] ?? ''));
    $profileForm['phone'] = trim((string) ($user['phone'] ?? ''));
    $profileForm['birthdate'] = trim((string) ($user['birthdate'] ?? ''));
    $profileForm['gender'] = trim((string) ($user['gender'] ?? ''));
    $profileForm['address_line'] = trim((string) ($user['address_line'] ?? ''));
    $profileForm['city'] = trim((string) ($user['city'] ?? ''));
    $profileForm['province'] = trim((string) ($user['province'] ?? ''));
    $profileForm['zip_code'] = trim((string) ($user['zip_code'] ?? ''));
}

$flash = account_flash_get();

if ($trackingReady && $deliveryChatReady) {
    $stmt = db()->prepare("SELECT o.id, o.total, o.status, o.created_at, s.tracking_status, s.tracking_number, s.courier_name, d.delivery_status, d.delivery_provider
        FROM orders o
        LEFT JOIN order_shipments s ON s.order_id = o.id
        LEFT JOIN order_delivery_messages d ON d.id = (
            SELECT MAX(dm.id) FROM order_delivery_messages dm WHERE dm.order_id = o.id
        )
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC");
} elseif ($trackingReady) {
    $stmt = db()->prepare('SELECT o.id, o.total, o.status, o.created_at, s.tracking_status, s.tracking_number, s.courier_name FROM orders o LEFT JOIN order_shipments s ON s.order_id = o.id WHERE o.user_id = ? ORDER BY o.created_at DESC');
} else {
    $stmt = db()->prepare('SELECT id, total, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC');
}
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$orders = $stmt->get_result();
$orderCount = $orders instanceof mysqli_result ? $orders->num_rows : 0;

$addressParts = array_filter([
    trim((string) ($user['address_line'] ?? '')),
    trim((string) ($user['city'] ?? '')),
    trim((string) ($user['province'] ?? '')),
    trim((string) ($user['zip_code'] ?? '')),
]);
$memberSince = trim((string) ($user['created_at'] ?? ''));
$fullName = trim((string) ($user['full_name'] ?? ''));
$firstInitial = $fullName !== '' ? strtoupper(substr($fullName, 0, 1)) : 'U';

$pageTitle = 'My Account';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section container">
    <div class="grid account-profile-grid">
        <div class="panel reveal account-hero-card">
            <div class="account-avatar"><?= e($firstInitial) ?></div>
            <div>
                <h1 style="margin:0 0 8px;">Welcome, <?= e($fullName) ?></h1>
                <p style="margin:0 0 8px;">Username: <strong>@<?= e((string) ($user['username'] ?? '')) ?></strong></p>
                <p style="margin:0;">Email: <?= e((string) ($user['email'] ?? '')) ?></p>
            </div>
            <div class="account-kpis">
                <span class="status-badge">Orders: <?= (int) $orderCount ?></span>
                <span class="status-badge"><?= is_valid_ph_mobile((string) ($user['phone'] ?? '')) ? 'PH Mobile Verified' : 'Mobile Needs Update' ?></span>
                <?php if ($memberSince !== ''): ?><span class="status-badge">Member Since: <?= e(date('M Y', strtotime($memberSince) ?: time())) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="panel reveal account-info-card">
            <h3>Saved Contact & Address</h3>
            <p><strong>Mobile:</strong> <?= e(format_ph_mobile((string) ($user['phone'] ?? ''))) ?></p>
            <?php if (trim((string) ($user['birthdate'] ?? '')) !== ''): ?><p><strong>Birthdate:</strong> <?= e((string) $user['birthdate']) ?></p><?php endif; ?>
            <?php if (trim((string) ($user['gender'] ?? '')) !== ''): ?><p><strong>Gender:</strong> <?= e(ucwords(str_replace('_', ' ', (string) $user['gender']))) ?></p><?php endif; ?>
            <?php if ($addressParts): ?><p><strong>Address:</strong> <?= e(implode(', ', $addressParts)) ?></p><?php endif; ?>
            <p style="margin-top:12px;"><a class="btn" href="<?= BASE_URL ?>/support.php">Open Customer Service</a></p>
        </div>
    </div>
</section>

<section class="section container">
    <div class="panel reveal">
        <h2>Update Profile</h2>
        <?php if ($flash): ?>
            <p style="color:<?= ($flash['type'] ?? '') === 'error' ? '#b30000' : '#2d7a32' ?>;"><?= e((string) ($flash['message'] ?? '')) ?></p>
        <?php endif; ?>
        <form method="post" class="account-edit-grid">
            <input type="hidden" name="action" value="update_profile">
            <div style="grid-column:1 / -1;">
                <label>Full Name</label>
                <input type="text" name="full_name" value="<?= e($profileForm['full_name']) ?>" required>
            </div>
            <div>
                <label>Username</label>
                <input type="text" name="username" value="<?= e($profileForm['username']) ?>" pattern="^[a-zA-Z0-9_.-]{3,30}$" required>
            </div>
            <div>
                <label>Mobile Number (PH only)</label>
                <input type="tel" name="phone" value="<?= e($profileForm['phone']) ?>" pattern="^(09[0-9]{9}|[+]639[0-9]{9}|639[0-9]{9})$" title="Use Philippine mobile format: 09XXXXXXXXX" required>
            </div>
            <div>
                <label>Birthdate</label>
                <input type="date" name="birthdate" value="<?= e($profileForm['birthdate']) ?>">
            </div>
            <div>
                <label>Gender</label>
                <select name="gender">
                    <option value="" <?= $profileForm['gender'] === '' ? 'selected' : '' ?>>Select gender</option>
                    <option value="female" <?= $profileForm['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                    <option value="male" <?= $profileForm['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                    <option value="prefer_not_to_say" <?= $profileForm['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
                </select>
            </div>
            <div style="grid-column:1 / -1;">
                <label>Address Line</label>
                <input type="text" name="address_line" value="<?= e($profileForm['address_line']) ?>" required>
            </div>
            <div>
                <label>City / Municipality</label>
                <input type="text" name="city" value="<?= e($profileForm['city']) ?>" required>
            </div>
            <div>
                <label>Province</label>
                <input type="text" name="province" value="<?= e($profileForm['province']) ?>" required>
            </div>
            <div>
                <label>ZIP Code</label>
                <input type="text" name="zip_code" value="<?= e($profileForm['zip_code']) ?>">
            </div>
            <div style="align-self:end;">
                <button class="btn btn-gold">Save Profile</button>
            </div>
        </form>
    </div>
</section>

<section class="section container">
    <h2 class="section-title reveal">Order History</h2>
    <div class="panel">
        <table>
            <thead><tr><th>Order Number</th><th>Date</th><th>Status</th><th>Tracking</th><th>Total</th><th>Action</th></tr></thead>
            <tbody>
            <?php while ($order = $orders->fetch_assoc()): ?>
                <?php $displayOrderNumber = order_display_number((int) $order['id']); ?>
                <tr>
                    <td><?= e($displayOrderNumber) ?></td>
                    <td><?= e((string) $order['created_at']) ?></td>
                    <td><?= e((string) $order['status']) ?></td>
                    <td>
                        <?php if (!$trackingReady): ?>
                            Logistics unavailable
                        <?php elseif ($deliveryChatReady && !empty($order['delivery_status'])): ?>
                            <span class="status-badge <?= e(tracking_badge_class((string) $order['delivery_status'])) ?>"><?= e(delivery_chat_status_label((string) $order['delivery_status'])) ?></span><br>
                            <small><?= e(delivery_provider_label((string) ($order['delivery_provider'] ?? 'staff'))) ?></small>
                        <?php elseif (!empty($order['tracking_status'])): ?>
                            <span class="status-badge <?= e(tracking_badge_class((string) $order['tracking_status'])) ?>"><?= e(shipment_status_label((string) $order['tracking_status'])) ?></span><br>
                            <small><?= e((string) ($order['courier_name'] ?? '')) ?> <?= !empty($order['tracking_number']) ? ' | ' . e((string) $order['tracking_number']) : '' ?></small>
                        <?php else: ?>
                            Not yet assigned
                        <?php endif; ?>
                    </td>
                    <td><?= money((float) $order['total']) ?></td>
                    <td><a class="btn" href="<?= BASE_URL ?>/tracking.php?id=<?= (int) $order['id'] ?>">Track</a></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
$stmt->close();
require_once __DIR__ . '/includes/footer.php';
?>
