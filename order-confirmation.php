<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$pageTitle = 'Order Confirmation';
require_once __DIR__ . '/includes/header.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT id, total, created_at, status, payment_method FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->bind_param('ii', $id, $_SESSION['user_id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    exit('Order not found');
}
$displayOrderNumber = order_display_number((int) $order['id']);
?>
<section class="section container">
    <div class="panel reveal" style="text-align:center;">
        <h1>Thank You For Your Order</h1>
        <p>Order <?= e($displayOrderNumber) ?> has been created successfully.</p>
        <p>Status: <?= e($order['status']) ?></p>
        <p>Payment: <?= e($order['payment_method']) ?></p>
        <p><strong><?= $order['status'] === 'paid' ? 'Total Paid' : 'Order Total' ?>: <?= money((float) $order['total']) ?></strong></p>
        <?php if ($order['status'] !== 'paid'): ?>
            <p>Your payment is still pending confirmation.</p>
        <?php endif; ?>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
            <a class="btn btn-gold" href="<?= BASE_URL ?>/account.php">View Order History</a>
            <a class="btn" href="<?= BASE_URL ?>/tracking.php?id=<?= (int) $order['id'] ?>">Track Shipment</a>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
