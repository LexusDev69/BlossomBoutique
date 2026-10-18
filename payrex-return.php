<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/payrex.php';
require_once __DIR__ . '/includes/order_payment.php';

function payrex_status_notice(string $status): string
{
    return match ($status) {
        'waiting' => 'Payment is still waiting for completion on the PayRex checkout page.',
        'processing' => 'Payment is being processed by PayRex.',
        'paid', 'succeeded', 'success', 'completed', 'confirmed', 'authorized', 'reserved' => 'Payment was completed successfully.',
        'cancelled', 'canceled' => 'The PayRex payment was cancelled.',
        'failed' => 'The PayRex payment failed.',
        default => 'Payment is not completed yet. Current status: ' . ($status !== '' ? $status : 'pending'),
    };
}

$orderId = (int) ($_GET['order_id'] ?? 0);
$checkoutSessionId = trim((string) ($_GET['checkout_session_id'] ?? $_GET['session_id'] ?? ''));
$paymentIntentId = trim((string) ($_GET['payment_intent_id'] ?? ''));
$result = strtolower(trim((string) ($_GET['result'] ?? '')));
$tokenExpires = (int) ($_GET['expires'] ?? 0);
$token = trim((string) ($_GET['token'] ?? ''));
$hasValidReturnToken = payrex_verify_return_token($orderId, $tokenExpires, $token);
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($orderId < 1) {
    http_response_code(404);
    exit('Order not found.');
}

$stmt = db()->prepare('SELECT id, user_id, total, status, payment_method, payrex_checkout_session_id, payrex_payment_intent_id FROM orders WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$isOrderOwner = (int) $order['user_id'] === $sessionUserId && $sessionUserId > 0;
$isReturnResult = in_array($result, ['success', 'cancel', 'failed'], true);
$isPayrexOrder = in_array((string) ($order['payment_method'] ?? ''), ['PayRex Payment', 'PayRex Checkout'], true);
$allowGuestReturnView = !$isOrderOwner && !$hasValidReturnToken && $isReturnResult && $isPayrexOrder;
if (!$isOrderOwner && !$hasValidReturnToken && !$allowGuestReturnView) {
    $requestedUri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $fallbackPath = BASE_URL . '/payrex-return.php?order_id=' . $orderId;
    $redirectTarget = str_starts_with($requestedUri, BASE_URL . '/') ? $requestedUri : $fallbackPath;
    header('Location: ' . BASE_URL . '/login.php?redirect=' . rawurlencode($redirectTarget));
    exit;
}

if ($checkoutSessionId === '') {
    $checkoutSessionId = trim((string) ($order['payrex_checkout_session_id'] ?? ''));
}
if ($paymentIntentId === '') {
    $paymentIntentId = trim((string) ($order['payrex_payment_intent_id'] ?? ''));
}

if ($checkoutSessionId !== '' && $checkoutSessionId !== (string) ($order['payrex_checkout_session_id'] ?? '')) {
    attach_payrex_session_to_order($orderId, $checkoutSessionId);
}
if ($paymentIntentId !== '' && $paymentIntentId !== (string) ($order['payrex_payment_intent_id'] ?? '')) {
    attach_payrex_session_to_order($orderId, '', $paymentIntentId);
}

$error = '';
$notice = '';

if ((string) $order['status'] !== 'paid' && ($checkoutSessionId !== '' || $paymentIntentId !== '') && payrex_is_configured()) {
    try {
        if ($paymentIntentId !== '') {
            $sessionPayload = payrex_retrieve_transaction($paymentIntentId);
            $sessionStatus = payrex_extract_checkout_status($sessionPayload);
            $paymentReference = $paymentIntentId;
        } else {
            $sessionPayload = payrex_retrieve_checkout_session($checkoutSessionId);
            $sessionStatus = payrex_extract_checkout_status($sessionPayload);
            $paymentReference = $checkoutSessionId;
            $sessionResource = payrex_extract_resource($sessionPayload);
            $sessionFields = payrex_extract_checkout_resource_fields($sessionResource);
            $detectedPaymentIntentId = trim((string) ($sessionFields['payment_intent_id'] ?? ''));
            if ($detectedPaymentIntentId !== '' && $detectedPaymentIntentId !== $paymentIntentId) {
                $paymentIntentId = $detectedPaymentIntentId;
                attach_payrex_session_to_order($orderId, '', $paymentIntentId);
            }
        }
        if (payrex_status_is_paid($sessionStatus)) {
            finalize_order_as_paid($orderId, $paymentReference, 'PayRex Checkout');
            $order['status'] = 'paid';
            if ($isOrderOwner) {
                set_cart([]);
                header('Location: ' . BASE_URL . '/order-confirmation.php?id=' . $orderId);
                exit;
            }
            $notice = 'Payment was completed successfully. Log in to open the full order confirmation page.';
        }
        if ($notice === '') {
            $notice = payrex_status_notice($sessionStatus);
        }
    } catch (Throwable $e) {
        $error = 'Could not verify payment yet. Please refresh this page in a few seconds.';
    }
} elseif ((string) $order['status'] === 'paid') {
    if ($isOrderOwner) {
        set_cart([]);
        header('Location: ' . BASE_URL . '/order-confirmation.php?id=' . $orderId);
        exit;
    }
    $notice = 'Payment was already completed. Log in to open the full order confirmation page.';
}

if ($result === 'cancel' && $notice === '') {
    $notice = 'Checkout was canceled. Your order remains pending until payment is completed.';
}

$pageTitle = 'PayRex Payment Status';
require_once __DIR__ . '/includes/header.php';
$displayOrderNumber = order_display_number($orderId);
$refreshParams = ['order_id' => $orderId];
if ($result !== '') {
    $refreshParams['result'] = $result;
}
if ($checkoutSessionId !== '') {
    $refreshParams['checkout_session_id'] = $checkoutSessionId;
}
if ($paymentIntentId !== '') {
    $refreshParams['payment_intent_id'] = $paymentIntentId;
}
if ($hasValidReturnToken) {
    $refreshParams['expires'] = $tokenExpires;
    $refreshParams['token'] = $token;
}
$refreshUrl = BASE_URL . '/payrex-return.php?' . http_build_query($refreshParams, '', '&', PHP_QUERY_RFC3986);
$orderPagePath = BASE_URL . '/order-confirmation.php?id=' . (int) $orderId;
$orderPageUrl = $isOrderOwner ? $orderPagePath : (BASE_URL . '/login.php?redirect=' . rawurlencode($orderPagePath));
$accountPath = BASE_URL . '/account.php';
$accountUrl = $isOrderOwner ? $accountPath : (BASE_URL . '/login.php?redirect=' . rawurlencode($accountPath));
?>
<section class="section container">
    <div class="panel reveal" style="max-width:780px;margin:0 auto;">
        <h1>PayRex Payment Status</h1>
        <p>Order <?= e($displayOrderNumber) ?></p>
        <p>Total: <strong><?= money((float) $order['total']) ?></strong></p>
        <p>Current Order Status: <strong><?= e((string) $order['status']) ?></strong></p>
        <?php if ($notice !== ''): ?><p><?= e($notice) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p style="color:#b30000;"><?= e($error) ?></p><?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a class="btn btn-gold" href="<?= e($refreshUrl) ?>">Refresh Payment Status</a>
            <a class="btn" href="<?= e($orderPageUrl) ?>">Open Order Page</a>
            <a class="btn" href="<?= e($accountUrl) ?>">My Account</a>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
