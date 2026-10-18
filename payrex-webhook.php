<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/payrex.php';
require_once __DIR__ . '/includes/order_payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$payload = file_get_contents('php://input');
if (!is_string($payload)) {
    $payload = '';
}

$signatureHeader = trim((string) ($_SERVER['HTTP_PAYREX_SIGNATURE'] ?? $_SERVER['HTTP_PAYREXX_SIGNATURE'] ?? ''));
if (PAYREX_WEBHOOK_SECRET !== '') {
    if ($payload === '' || !payrex_verify_webhook_signature($payload, $signatureHeader, PAYREX_WEBHOOK_SECRET)) {
        http_response_code(400);
        echo 'Invalid signature.';
        exit;
    }
}

$event = [];
if ($payload !== '') {
    $decoded = json_decode($payload, true);
    if (is_array($decoded)) {
        $event = $decoded;
    }
}

$eventType = strtolower(trim((string) ($event['type'] ?? $event['data']['attributes']['type'] ?? '')));
$resource = [];
if (is_array($event['data']['resource'] ?? null)) {
    $resource = $event['data']['resource'];
} elseif (is_array($event['data']['attributes']['data']['resource'] ?? null)) {
    $resource = $event['data']['attributes']['data']['resource'];
} elseif (is_array($event['data']['attributes']['resource'] ?? null)) {
    $resource = $event['data']['attributes']['resource'];
}

$resourceType = strtolower(trim((string) ($resource['resource'] ?? $resource['type'] ?? '')));
$resourceId = trim((string) ($resource['id'] ?? ''));
$resourceStatus = strtolower(trim((string) ($resource['status'] ?? '')));
$metadata = is_array($resource['metadata'] ?? null) ? $resource['metadata'] : [];

$orderId = (int) ($metadata['order_id'] ?? 0);
$checkoutSessionId = trim((string) ($resource['checkout_session_id'] ?? ''));
$paymentIntentId = trim((string) ($resource['payment_intent_id'] ?? ''));
$paymentId = trim((string) ($resource['payment_id'] ?? ''));

if ($resourceType === 'checkout_session' && $resourceId !== '') {
    $checkoutSessionId = $resourceId;
}
if ($resourceType === 'payment_intent' && $resourceId !== '') {
    $paymentIntentId = $resourceId;
}
if ($resourceType === 'payment' && $resourceId !== '') {
    $paymentId = $resourceId;
}

if ($paymentIntentId === '' && is_array($resource['payment_intent'] ?? null)) {
    $paymentIntentId = trim((string) ($resource['payment_intent']['id'] ?? ''));
} elseif ($paymentIntentId === '' && is_string($resource['payment_intent'] ?? null)) {
    $paymentIntentId = trim((string) $resource['payment_intent']);
}

if ($orderId < 1) {
    $referenceId = trim((string) ($resource['customer_reference_id'] ?? $resource['referenceId'] ?? ''));
    if ($referenceId !== '' && ctype_digit($referenceId)) {
        $orderId = (int) $referenceId;
    }
}

$isPaidEvent = str_contains($eventType, 'paid')
    || str_contains($eventType, 'succeeded')
    || str_contains($eventType, 'completed')
    || str_contains($eventType, 'captured');
$isFailedEvent = str_contains($eventType, 'failed')
    || str_contains($eventType, 'cancelled')
    || str_contains($eventType, 'canceled')
    || str_contains($eventType, 'expired');

if ($resourceStatus !== '') {
    if (payrex_status_is_paid($resourceStatus)) {
        $isPaidEvent = true;
        $isFailedEvent = false;
    } elseif (in_array($resourceStatus, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
        $isPaidEvent = false;
        $isFailedEvent = true;
    }
}

$transactionId = $paymentIntentId !== '' ? $paymentIntentId : ($paymentId !== '' ? $paymentId : $checkoutSessionId);
if ($transactionId !== '' && payrex_is_configured()) {
    try {
        $transactionPayload = payrex_retrieve_transaction($transactionId);
        if ($orderId < 1) {
            $extractedReferenceId = payrex_extract_reference_id($transactionPayload);
            if ($extractedReferenceId !== '' && ctype_digit($extractedReferenceId)) {
                $orderId = (int) $extractedReferenceId;
            }
        }
        $transactionStatus = payrex_extract_checkout_status($transactionPayload);
        if ($transactionStatus !== '') {
            $isPaidEvent = payrex_status_is_paid($transactionStatus);
            $isFailedEvent = in_array($transactionStatus, ['failed', 'cancelled', 'expired', 'declined'], true);
        }
        if ($paymentIntentId === '' && str_starts_with($transactionId, 'pi_')) {
            $paymentIntentId = $transactionId;
        }
    } catch (Throwable $e) {
        payrex_log_error('PayRex webhook transaction fetch failed', [
            'event_type' => $eventType,
            'transaction_id' => $transactionId,
            'error' => $e->getMessage(),
        ]);
    }
}

if ($orderId < 1) {
    $orderId = find_order_id_by_payrex_reference($checkoutSessionId, $paymentIntentId);
}

if ($orderId < 1) {
    http_response_code(200);
    echo 'No matching order.';
    exit;
}

if ($checkoutSessionId !== '' || $paymentIntentId !== '') {
    attach_payrex_session_to_order($orderId, $checkoutSessionId, $paymentIntentId);
}

try {
    $reference = $paymentIntentId !== '' ? $paymentIntentId : ($checkoutSessionId !== '' ? $checkoutSessionId : $paymentId);
    if ($isPaidEvent) {
        finalize_order_as_paid($orderId, $reference, 'PayRex Checkout');
    } elseif ($isFailedEvent) {
        mark_order_as_cancelled_if_pending($orderId, $reference);
    }
} catch (Throwable $e) {
    payrex_log_error('PayRex webhook processing failed', [
        'order_id' => $orderId,
        'event_type' => $eventType,
        'error' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo 'Webhook processing failed.';
    exit;
}

http_response_code(200);
echo 'OK';
