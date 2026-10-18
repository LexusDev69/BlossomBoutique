<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/helpers.php';

$redirect = trim((string) ($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
if ($redirect === '' || !str_starts_with($redirect, BASE_URL . '/')) {
    $redirect = BASE_URL . '/account.php';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = (int) $user['id'];
        header('Location: ' . $redirect);
        exit;
    }
    $error = 'Invalid credentials.';
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section container">
    <div class="panel reveal" style="max-width:520px;margin:0 auto;">
        <h1>Login</h1>
        <?php if ($error): ?><p style="color:#b30000;"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button class="btn btn-gold">Login</button>
        </form>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
