<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';

$profileReady = ensure_user_profile_schema();

$pageTitle = 'Signup';
require_once __DIR__ . '/includes/header.php';

$error = '';
$form = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'birthdate' => '',
    'gender' => '',
    'address_line' => '',
    'city' => '',
    'province' => '',
    'zip_code' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $form['username'] = trim((string) ($_POST['username'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $form['birthdate'] = trim((string) ($_POST['birthdate'] ?? ''));
    $form['gender'] = trim((string) ($_POST['gender'] ?? ''));
    $form['address_line'] = trim((string) ($_POST['address_line'] ?? ''));
    $form['city'] = trim((string) ($_POST['city'] ?? ''));
    $form['province'] = trim((string) ($_POST['province'] ?? ''));
    $form['zip_code'] = trim((string) ($_POST['zip_code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $birthdateValue = $form['birthdate'] !== '' ? $form['birthdate'] : null;

    if (!$profileReady) {
        $error = 'User profile fields are not ready yet. Run the user profile migration and try again.';
    } elseif (
        $form['full_name'] === '' ||
        $form['username'] === '' ||
        $form['email'] === '' ||
        $form['phone'] === '' ||
        $form['address_line'] === '' ||
        $form['city'] === '' ||
        $form['province'] === '' ||
        strlen($password) < 6
    ) {
        $error = 'Please complete the required fields. Password must be at least 6 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $form['username'])) {
        $error = 'Username must be 3 to 30 characters and use only letters, numbers, dot, dash, or underscore.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!is_valid_ph_mobile($form['phone'])) {
        $error = 'Please enter a valid Philippine mobile number (example: 09XXXXXXXXX).';
    } elseif ($confirmPassword !== $password) {
        $error = 'Password confirmation does not match.';
    } else {
        $checkStmt = db()->prepare('SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 2');
        $duplicateField = '';
        if ($checkStmt instanceof mysqli_stmt) {
            $checkStmt->bind_param('ss', $form['username'], $form['email']);
            $checkStmt->execute();
            $duplicates = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $checkStmt->close();
            foreach ($duplicates as $duplicate) {
                if ((string) ($duplicate['username'] ?? '') === $form['username']) {
                    $duplicateField = 'username';
                    break;
                }
                if ((string) ($duplicate['email'] ?? '') === $form['email']) {
                    $duplicateField = 'email';
                }
            }
        }

        if ($duplicateField === 'username') {
            $error = 'Username is already taken. Please choose another one.';
        } elseif ($duplicateField === 'email') {
            $error = 'This email already has an account.';
        }
    }

    if ($error === '') {
        $normalizedPhone = normalize_ph_mobile($form['phone']);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = db()->prepare('INSERT INTO users (full_name, username, email, password_hash, phone, birthdate, gender, address_line, city, province, zip_code, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "customer")');
        $stmt->bind_param(
            'sssssssssss',
            $form['full_name'],
            $form['username'],
            $form['email'],
            $hash,
            $normalizedPhone,
            $birthdateValue,
            $form['gender'],
            $form['address_line'],
            $form['city'],
            $form['province'],
            $form['zip_code']
        );
        if ($stmt->execute()) {
            $_SESSION['user_id'] = db()->insert_id;
            $stmt->close();
            header('Location: ' . BASE_URL . '/account.php');
            exit;
        }
        $errno = $stmt->errno;
        $stmt->close();
        $error = $errno === 1062
            ? 'Username or email is already taken.'
            : 'Unable to create account right now.';
    }
}
?>
<section class="section container">
    <div class="panel reveal" style="max-width:760px;margin:0 auto;">
        <h1>Create Account</h1>
        <?php if ($error): ?><p style="color:#b30000;"><?= e($error) ?></p><?php endif; ?>
        <form method="post" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
            <div style="grid-column:1 / -1;">
                <input type="text" name="full_name" placeholder="Full name" value="<?= e($form['full_name']) ?>" required>
            </div>
            <input type="text" name="username" placeholder="Username" value="<?= e($form['username']) ?>" required>
            <input type="email" name="email" placeholder="Email" value="<?= e($form['email']) ?>" required>
            <input type="tel" name="phone" placeholder="09XXXXXXXXX" value="<?= e($form['phone']) ?>" pattern="^(09[0-9]{9}|[+]639[0-9]{9}|639[0-9]{9})$" title="Use Philippine mobile format: 09XXXXXXXXX" required>
            <input type="date" name="birthdate" value="<?= e($form['birthdate']) ?>">
            <select name="gender">
                <option value="" <?= $form['gender'] === '' ? 'selected' : '' ?>>Gender (optional)</option>
                <option value="female" <?= $form['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="male" <?= $form['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="prefer_not_to_say" <?= $form['gender'] === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
            </select>
            <div style="grid-column:1 / -1;">
                <input type="text" name="address_line" placeholder="Address line / house no. / street / barangay" value="<?= e($form['address_line']) ?>" required>
            </div>
            <input type="text" name="city" placeholder="City / Municipality" value="<?= e($form['city']) ?>" required>
            <input type="text" name="province" placeholder="Province" value="<?= e($form['province']) ?>" required>
            <input type="text" name="zip_code" placeholder="ZIP code" value="<?= e($form['zip_code']) ?>">
            <div></div>
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm password" required>
            <div style="grid-column:1 / -1;">
                <p style="margin:0 0 6px;">This helps your account, delivery records, and admin order handling show more complete customer information.</p>
            </div>
            <button class="btn btn-gold">Signup</button>
        </form>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
