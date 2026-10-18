<?php
declare(strict_types=1);
$pageTitle = 'Cart';
require_once __DIR__ . '/includes/header.php';
$viewer = current_user();

if (($viewer['role'] ?? '') === 'admin') {
    set_cart(['products' => [], 'custom' => []]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cart = get_cart();
    foreach ($_POST['qty_product'] ?? [] as $pid => $qty) {
        $pidInt = (int) $pid;
        $qtyInt = max(0, (int) $qty);
        if ($pidInt < 1) {
            continue;
        }
        if ($qtyInt === 0) {
            unset($cart['products'][$pidInt]);
        } else {
            $cart['products'][$pidInt] = $qtyInt;
        }
    }
    foreach ($_POST['qty_custom'] ?? [] as $customKey => $qty) {
        $customKey = trim((string) $customKey);
        $qtyInt = max(0, (int) $qty);
        if ($customKey === '' || !isset($cart['custom'][$customKey])) {
            continue;
        }
        if ($qtyInt === 0) {
            unset($cart['custom'][$customKey]);
        } else {
            $cart['custom'][$customKey]['quantity'] = $qtyInt;
        }
    }
    set_cart($cart);
    header('Location: ' . BASE_URL . '/cart.php');
    exit;
}

$totals = cart_totals();
?>
<section class="section container">
    <h1 class="section-title reveal">Your Cart</h1>
    <?php if (($viewer['role'] ?? '') === 'admin'): ?>
        <div class="panel">Admin accounts cannot place customer orders. Product editing is available in the admin dashboard.</div>
    <?php elseif (!$totals['items']): ?>
        <div class="panel">Your cart is empty. <a href="<?= BASE_URL ?>/shop.php">Continue shopping</a>.</div>
    <?php else: ?>
        <form method="post" class="panel reveal">
            <table>
                <thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($totals['items'] as $item): ?>
                    <tr>
                        <td>
                            <strong><?= e($item['name']) ?></strong>
                            <?php if (($item['type'] ?? 'product') === 'custom'): ?>
                                <p style="margin:6px 0 0;">Flower: <?= e((string) ($item['flower_name'] ?? 'N/A')) ?> | Wrap: <?= e((string) ($item['wrap_name'] ?? 'N/A')) ?></p>
                                <?php if (trim((string) ($item['description'] ?? '')) !== ''): ?>
                                    <p style="margin:4px 0 0;"><?= e((string) $item['description']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($item['addons']) && is_array($item['addons'])): ?>
                                    <p style="margin:4px 0 0;">Add-ons:
                                        <?php
                                        $addonNames = [];
                                        foreach ($item['addons'] as $addon) {
                                            if (is_array($addon) && isset($addon['name'])) {
                                                $addonNames[] = (string) $addon['name'];
                                            }
                                        }
                                        echo e(implode(', ', $addonNames));
                                        ?>
                                    </p>
                                <?php endif; ?>
                                <?php if (trim((string) ($item['message_note'] ?? '')) !== ''): ?>
                                    <p style="margin:4px 0 0;"><small><?= nl2br(e((string) $item['message_note'])) ?></small></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= money($item['price']) ?></td>
                        <td>
                            <?php if (($item['type'] ?? 'product') === 'custom'): ?>
                                <input type="number" min="0" max="20" name="qty_custom[<?= e((string) ($item['cart_key'] ?? '')) ?>]" value="<?= $item['quantity'] ?>">
                            <?php else: ?>
                                <input type="number" min="0" max="<?= $item['stock'] ?>" name="qty_product[<?= $item['id'] ?>]" value="<?= $item['quantity'] ?>">
                            <?php endif; ?>
                        </td>
                        <td><?= money($item['line_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><strong>Subtotal: <?= money($totals['subtotal']) ?></strong></p>
            <button class="btn">Update Cart</button>
            <a class="btn btn-gold" href="<?= BASE_URL ?>/checkout.php">Proceed to Checkout</a>
        </form>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
